<?php
/*
 * Copyright (c) 2009 - 2010, Frosted Design
 * All rights reserved.
 */

namespace hydrogen\view;

use hydrogen\common\TypedValue;
use hydrogen\view\Lexer;
use hydrogen\view\exceptions\NoSuchVariableException;
use hydrogen\view\exceptions\NoSuchFilterException;
use hydrogen\view\exceptions\TemplateSyntaxException;

class ExpressionEvaluator {

	const TOKEN_NONE = 0;
	const TOKEN_OP = 1;
	const TOKEN_COMP = 2;
	const TOKEN_JOIN = 3;
	const TOKEN_NUM = 4;
	const TOKEN_VAR = 5;
	const TOKEN_OPENGROUP = 6;
	const TOKEN_CLOSEGROUP = 7;
	const TOKEN_INVERT = 8;
	const TOKEN_FUNC = 9;

	protected static $operators = array('-', '+', '/', '*', '%');

	protected static $comparators = array('<', '>', '==', '!=', '<=', '>=');

	protected static $joiners = array('&&', '||');

	protected static $functions = array("in", "empty");

	protected static $varTranslations = array(
		"and" => array(self::TOKEN_JOIN, '&&'),
		"or" => array(self::TOKEN_JOIN, '||'),
		"not" => array(self::TOKEN_INVERT, "!")
	);

	protected static $comparatorTranslations = array(
		"=" => array(self::TOKEN_COMP, '=='),
		"!" => array(self::TOKEN_INVERT, '!')
	);

	// This is a statically accessed class
	protected function __construct() {}

	public static function evaluate($expr, $context) {
		$php = static::exprToPHP($expr);
		return eval("return $php;");
	}

	public static function exprToPHP(&$expr) {
		$state = self::TOKEN_NONE;
		$token = '';
		$tokens = array();
		$len = strlen($expr);
		$poss = array();
		$lastToken = self::TOKEN_NONE;
		$varInQuotes = false;
		$varEscaping = false;
		$varInFilter = false;
		$numHasDot = false;
		$groupRatio = 0;
		for ($i = 0; $i <= $len; $i++) {
			if ($i === $len)
				$char = ' ';
			else
				$char = $expr[$i];
			switch ($state) {
				case self::TOKEN_NONE:
					// Determine what state we should be in
					$token = $char;
					// Test for digit or decimal
					if (ctype_digit($char) || $char === '.')
						$state = self::TOKEN_NUM;
					// Test for alphabetical char
					else if (ctype_alpha($char))
						$state = self::TOKEN_VAR;
					// Test for operator
					else if (count($poss = static::filterArrayStartsWith(
							$char, static::$operators)) > 0)
						$state = self::TOKEN_OP;
					// Test for comparison char
					else if (count($poss = static::filterArrayStartsWith(
							$char, static::$comparators)) > 0)
						$state = self::TOKEN_COMP;
					// Test for joining char
					else if (count($poss = static::filterArrayStartsWith(
							$char, static::$joiners)) > 0)
						$state = self::TOKEN_JOIN;
					// Test for open group
					else if ($char === '(')
						$state = self::TOKEN_OPENGROUP;
					// Test for close group
					else if ($char === ')')
						$state = self::TOKEN_CLOSEGROUP;
					// Spaces are legal, but nothing else
					else if ($char !== ' ')
						throw new TemplateSyntaxException(
							"Illegal character '" . $char .
							"' (ASCII " . ord($char) . ") in expression: '" .
							$expr . "'");
					break;
				case self::TOKEN_NUM:
					// Current char must be numeric or decimal
					if ($token === '.')
						$numHasDot = true;
					if (ctype_digit($char))
						$token .= $char;
					else if ($char === '.') {
						if (!$numHasDot) {
							$token .= $char;
							$numHasDot = true;
						}
						else
							throw new TemplateSyntaxException(
								"Number '" . $token .
								"' cannot have multiple decimal points in expression: '" .
								$expr . "'");
					}
					else if ($lastToken === self::TOKEN_NONE ||
							$lastToken === self::TOKEN_COMP ||
							$lastToken === self::TOKEN_JOIN ||
							$lastToken === self::TOKEN_OP ||
							$lastToken === self::TOKEN_OPENGROUP) {
						$tokens[] = new TypedValue(self::TOKEN_NUM, $token);
						$lastToken = self::TOKEN_NUM;
						$state = self::TOKEN_NONE;
						$numHasDot = false;
						$i--;
					}
					else
						throw new TemplateSyntaxException(
							"Misplaced numeric '" . $token .
							"' in expression: '" . $expr . "'");
					break;
				case self::TOKEN_VAR:
					$endClean = false;
					// If we're in quotes, allow anything
					if ($varInQuotes) {
						if ($char === '"' && $varEscaping === false)
							$varInQuotes = false;
						else if ($char === '\\' || $varEscaping === true)
							$varEscaping = !$varEscaping;
						$token .= $char;
					}
					else if ($char === Lexer::VARIABLE_FILTER_SEPARATOR) {
						$token .= $char;
						$varInFilter = true;
					}
					else if ($varInFilter === true) {
						// If the last character was a filter separator,
						// allow only alphas.  Otherwise, all legals work.
						$lastChar = $token[strlen($token) - 1];
						if (($lastChar === Lexer::VARIABLE_FILTER_SEPARATOR &&
								ctype_alpha($char)) || ($lastChar !==
								Lexer::VARIABLE_FILTER_SEPARATOR &&
								(ctype_alnum($char) || $char === '_' ||
								$char ===
								Lexer::VARIABLE_FILTER_ARGUMENT_SEPARATOR)))
							$token .= $char;
						else if ($lastChar ===
								Lexer::VARIABLE_FILTER_SEPARATOR ||
								$lastChar ===
								Lexer::VARIABLE_FILTER_ARGUMENT_SEPARATOR)
							throw new TemplateSyntaxException(
							"Illegal character in filter: '" . $char .
							"' for expression: '" . $expr . "'.");
						else
							$endClean = true;
					}
					else {
						// We must be in the normal variable name.  Allow
						// alphanumerics, underscores, and variable level
						// separators.
						if (ctype_alnum($char) || $char === '_' ||
								$char === Lexer::VARIABLE_LEVEL_SEPARATOR)
							$token .= $char;
						else
							$endClean = true;
					}
					if ($endClean) {
						// Do we need to translate the result?
						if (isset(static::$varTranslations[$token])) {
							$state = static::$varTranslations[$token][0];
							$token = static::$varTranslations[$token][1];
							$i--;
						}
						// Are we looking at a function instead of a variable?
						if (in_array($token, static::$functions)) {
							$state = self::TOKEN_NONE;
							$lastToken = self::TOKEN_FUNC;
							$tokens[] = new TypedValue(self::TOKEN_FUNC,
								$token);
							$i--;
						}
						// It's a variable! Bag it and tag it.
						else if ($lastToken === self::TOKEN_NONE ||
								$lastToken === self::TOKEN_COMP ||
								$lastToken === self::TOKEN_INVERT ||
								$lastToken === self::TOKEN_JOIN ||
								$lastToken === self::TOKEN_OP ||
								$lastToken === self::TOKEN_OPENGROUP) {
							$tokens[] = new TypedValue(self::TOKEN_VAR,
								$token);
							$state = self::TOKEN_NONE;
							$lastToken = self::TOKEN_VAR;
							$varInFilter = false;
							$varEscaping = false;
							$varInQuotes = false;
							$i--;
						}
						else
							throw new TemplateSyntaxException(
								"Misplaced variable '" . $token .
								"' in expression: '" . $expr . "'");
					}
					break;
				case self::TOKEN_OP:
				case self::TOKEN_COMP:
				case self::TOKEN_JOIN:
					$poss = static::filterArrayStartsWith($token . $char,
						$poss);
					if (count($poss) > 0)
						$token .= $char;
					// Check to see if this should be translated
					else if ($state === self::TOKEN_COMP &&
							isset(static::$comparatorTranslations[$token])) {
						$state = static::$comparatorTranslations[$token][0];
						$token = static::$comparatorTranslations[$token][1];
						$i--;
					}
					// If the token's a minus, see if this should be a number
					else if ($state === self::TOKEN_OP &&
							$token === '-' && ctype_digit($char) &&
							($lastToken === self::TOKEN_NONE ||
							$lastToken === self::TOKEN_COMP ||
							$lastToken === self::TOKEN_JOIN ||
							$lastToken === self::TOKEN_OP ||
							$lastToken === self::TOKEN_OPENGROUP)) {
						$state = self::TOKEN_NUM;
						$i--;
					}
					// We're all set!  It's whatever the state is.
					else if ($lastToken === self::TOKEN_VAR ||
							$lastToken === self::TOKEN_NUM ||
							$lastToken === self::TOKEN_CLOSEGROUP) {
						$tokens[] = new TypedValue($state, $token);
						$lastToken = $state;
						$state = self::TOKEN_NONE;
						$i--;
					}
					else
						throw new TemplateSyntaxException(
							"Misplaced '" . $token .
							"' in expression: '" . $expr . "'");
					break;
				case self::TOKEN_OPENGROUP:
					if ($lastToken !== self::TOKEN_VAR &&
							$lastToken !== self::TOKEN_NUM) {
						$tokens[] = new TypedValue(self::TOKEN_OPENGROUP,
							$token);
						$groupRatio++;
						$state = self::TOKEN_NONE;
						$lastToken = self::TOKEN_OPENGROUP;
						$i--;
					}
					else
						throw new TemplateSyntaxException(
							"Misplaced '(' in expression: '" .
							$expr . "'.");
					break;
				case self::TOKEN_CLOSEGROUP:
					if (($lastToken === self::TOKEN_VAR ||
							$lastToken === self::TOKEN_NUM ||
							$lastToken === self::TOKEN_CLOSEGROUP) &&
							$groupRatio > 0) {
						$tokens[] = new TypedValue(self::TOKEN_CLOSEGROUP,
							$token);
						$groupRatio--;
						$state = self::TOKEN_NONE;
						$lastToken = self::TOKEN_CLOSEGROUP;
						$i--;
					}
					else
						throw new TemplateSyntaxException(
							"Misplaced ')' in expression: '" .
							$expr . "'.");
					break;
				case self::TOKEN_INVERT:
					if ($lastToken === self::TOKEN_NONE ||
							$lastToken === self::TOKEN_COMP ||
							$lastToken === self::TOKEN_JOIN ||
							$lastToken === self::TOKEN_OPENGROUP) {
						$tokens[] = new TypedValue(self::TOKEN_INVERT, $token);
						$state = self::TOKEN_NONE;
						$lastToken = self::TOKEN_INVERT;
						$i--;
					}
					else
						throw new TemplateSyntaxException(
							"Misplaced '!' in expression: '" .
							$expr . "'.");
			}
		}
		if ($groupRatio !== 0)
			throw new TemplateSyntaxException("Missing ')' in expression: '" .
				$expr . "'.");

		// Parse the tokens
		$len = count($tokens);
		for ($i = 0; $i < $len; $i++) {
			if ($tokens[$i]->type === self::TOKEN_VAR)
				$tokens[$i]->value = '\\' . __NAMESPACE__ .
					'\ExpressionEvaluator::evalVariableString("' .
					$tokens[$i]->value . '", $context)';
		}
		return implode(' ', $tokens);
	}

	protected static function filterArrayStartsWith($needle, $haystack) {
		$num = count($haystack);
		$len = strlen($needle);
		for ($i = $num - 1; $i >= 0; $i--) {
			if ($len > strlen($haystack[$i]))
				array_splice($haystack, $i, 1);
			else {
				for ($q = 0; $q < $len; $q++) {
					if ($needle[$q] !== $haystack[$i][$q]) {
						array_splice($haystack, $i, 1);
						break;
					}
				}
			}
		}
		return $haystack;
	}

	public static function evalVariableTokens($variable, $drilldowns, $filters,
			$context) {
		$var = $context->get($variable);
		$level = 0;
		foreach ($drilldowns as $dd) {
			if (isset($var[$dd]))
				$var = $var[$dd];
			else if (isset($var->$dd))
				$var = $var->$dd;
			else if (is_object($var)) {
				$methods = get_class_methods($var);
				if (in_array(($func = "get" . ucfirst($dd)), $methods) ||
						in_array(($func = "is" . ucfirst($dd)), $methods) ||
						in_array(($func = "get_" . $dd), $methods) ||
						in_array(($func = "is_" . $dd), $methods))
					$var = call_user_func(array($var, $func));
			}
			else {
				$varName = $this->variable;
				for ($i = 0; $i <= $level; $i++)
					$varName .= '.' . $this->drilldowns[$i];
				$e = new NoSuchVariableException("Variable does not exist in context: $varName");
				$e->variable = $varName;
				throw $e;
			}
			$level++;
		}
		foreach ($filters as $filter) {
			$class = '\hydrogen\view\filters\\' .
				ucfirst(strtolower($filter->filter)) . 'Filter';
			if (!@class_exists($class)) {
				$e = new NoSuchFilterException('Filter does not exist: "' .
					$filter . '".');
				$e->filter = $filter;
				throw $e;
			}
			$var = $class::applyTo($var, $filter->args, $context);
		}
		return $var;
	}

	public static function evalVariableString($varString, $context) {
		$token = Lexer::getVariableToken("expr", $varString);
		return static::evalVariableTokens($token->variable, $token->drilldowns,
			$token->filters, $context);
	}
}

?>