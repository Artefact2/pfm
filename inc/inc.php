<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

require __DIR__.'/pfio.php';
require __DIR__.'/line.php';
require __DIR__.'/tx.php';
require __DIR__.'/status.php';
require __DIR__.'/quote.php';

function fatal(...$params) {
	fprintf(STDERR, ...$params);
	die(1);
}

function notice(...$params) {
	fprintf(STDERR, ...$params);
}

/* Get some cached data, or generate the data and cache it.
 *
 * @param $cutoff a timestamp or a negative integer. If timestamp,
 * generate new data if cached data is older than this timestamp. If
 * negative integer, generate new data if cached data is older than
 * $cutoff seconds.
 */
function get_cached_thing($id, $cutoff, callable $generate) {
	static $cachedir = null;

	if($cachedir === null) {
		$cachedir = getenv('XDG_CACHE_HOME');
		if($cachedir === false) $cachedir = getenv('HOME').'/.cache';
		$cachedir .= '/pfm';
		if(!is_dir($cachedir)) mkdir($cachedir, 0700, true);
	}

	$f = $cachedir.'/'.$id;

	if(file_exists($f)) {
		$m = filemtime($f);
		
		if(($cutoff >= 0 && $m > $cutoff) || ($cutoff < 0 && (time() - $m) < -$cutoff)) {
			return json_decode(file_get_contents($f), true);
		}
	}

	$data = $generate();
	file_put_contents($f, json_encode($data));
	return $data;
}

function print_header(array $fmt) {
	printf(
		implode(' |', array_column($fmt, 0))."\n",
		...array_keys($fmt)
	);
}

function print_row(array $fmt, array $row) {
	$v = [];
	foreach($fmt as $k => $val) {
		if(!isset($row[$k])) {
			$v[$k] = [ $val[0], '' ];
			continue;
		}

		if(!is_string($row[$k])) {
			$v[$k] = [ $val[1], $row[$k] ];
		} else {
			$v[$k] = [ $val[0], $row[$k] ];
		}
	}

	printf(
		implode(' |', array_column($v, 0))."\n",
		...array_column($v, 1)
	);
}

function print_sep(array $fmt) {
	echo strtr(sprintf(
		implode(' |', array_column($fmt, 0))."\n",
		...array_fill(0, count($fmt), '')
	), '| ', '+-');
}