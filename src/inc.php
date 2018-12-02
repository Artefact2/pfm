<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

assert_options(ASSERT_BAIL, true);

require __DIR__.'/pfio.php';
require __DIR__.'/line.php';
require __DIR__.'/tx.php';
require __DIR__.'/status.php';
require __DIR__.'/quote.php';
require __DIR__.'/gnuplot.php';
require __DIR__.'/irr.php';
require __DIR__.'/gnucash.php';

function fatal(...$params): void {
	fprintf(STDERR, ...$params);
	die(1);
}

function notice(...$params): void {
	fprintf(STDERR, ...$params);
}

/* Get some cached data, or generate the data and cache it.
 *
 * @param $ttl a timestamp or a negative integer. If timestamp,
 * generate new data once cached data is older than this timestamp. If
 * negative integer, generate new data when cached data gets older than
 * $ttl seconds.
 */
function get_cached_thing(string $id, int $ttl, callable $generate) {
	$cachedir = get_paths()['cache-home'];
	if(!is_dir($cachedir)) mkdir($cachedir, 0700, true);

	$f = $cachedir.'/'.$id;

	if(file_exists($f)) {
		if(time() < filemtime($f)) {
			return json_decode(file_get_contents($f), true);
		}
	}

	$data = $generate();
	file_put_contents($f, json_encode($data));
	touch($f, $ttl > 0 ? $ttl : time() - $ttl);
	return $data;
}

function maybe_strtotime(string $dt, ?int $now = null): int {
	if(is_numeric($dt)) {
		/* Assume absolute timestamp */
		return (int)$dt;
	} else {
		$ts = strtotime($dt, $now ?? time());
		if($ts === false) fatal("Unparseable datetime: %s\n", $dt);
		return $ts;
	}
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
	$sep = strtr(sprintf(
		implode(' |', array_column($fmt, 0))."\n",
		...array_fill(0, count($fmt), '')
	), '| ', '+-');
	assert(strlen($sep) <= 81);
	echo $sep;
}

function colorize_percentage($pc, $fmt = '%6.2f', $hi = null, $low = null, $neghi = null, $neglow = null, $label = null) {
	static $colorseqs = null;
	if($colorseqs === null) {
		$colorseqs = [
			'bold' => shell_exec('tput bold'),
			'red' => shell_exec('tput setaf 1'),
			'green' => shell_exec('tput setaf 2'),
			'magenta' => shell_exec('tput setaf 5'),
			'cyan' => shell_exec('tput setaf 6'),
			'reset' => shell_exec('tput sgr0'),
		];
	}

	if($hi === null) $hi = 3;
	if($low === null) $low = .5;
	if($neghi === null) $neghi = 100. / (1 + $hi / 100.) - 100;
	if($neglow === null) $neglow = 100. / (1 + $low / 100.) - 100;
	if($label === null) $label = $pc;

	$out = $colorseqs['bold'];

	if($pc > $hi) $out .= $colorseqs['green'];
	else if($pc > $low) $out .= $colorseqs['cyan'];
	else if($pc < $neghi) $out .= $colorseqs['magenta'];
	else if($pc < $neglow) $out .= $colorseqs['red'];

	$out .= sprintf($fmt, $label);
	$out .= $colorseqs['reset'];

	return $out;
}
