<?php
/*
 * Copyright (c) 2009 - 2011, Frosted Design
 * All rights reserved.
 */

namespace hydrogen\view\engines\hydrogen\filters;

use hydrogen\view\engines\hydrogen\Filter;

class Md5Filter implements Filter {

	public static function applyTo($string, $args, &$escape, $phpfile) {
		$escape = false;
		return 'md5(' . $string . ')';
	}

}

?>