<?php
/*
 * Copyright (c) 2009 - 2011, Frosted Design
 * All rights reserved.
 */

namespace hydrogen\log;

interface LogEngine {
	public function write($loglevel, $file, $line, $msg);
}

?>