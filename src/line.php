<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function add_line(array &$pf, $name, $ticker, $currency, $isin) {
	if(isset($pf['lines'][$ticker])) fatal("There is already a line with ticker %s\n", $ticker);

	$pf['lines'][$ticker] = [
		'name' => $name,
		'ticker' => $ticker,
		'currency' => $currency,
		'isin' => $isin,
	];
}

function rm_line(array &$pf, $ticker) {
	if(!isset($pf['lines'][$ticker])) fatal("Line not found: %s\n", $ticker);

	foreach($pf['tx'] as $tx) {
		if($tx['ticker'] === $ticker) {
			fatal("Line %s still has transactions, cannot remove\n", $ticker);
		}
	}

	foreach($pf['benchmark'] ?? [] as $bt => $w) {
		if($bt === $ticker) {
			fatal("Line %s is used in the benchmark, cannot remove\n", $ticker);
		}
	}

	unset($pf['lines'][$ticker]);
}

function ls_lines(array &$pf) {
	static $fmt = [
		'Name' => [ '%52s' ],
		'Tkr' => [ '%5s' ],
		'Cur' => [ '%4s' ],
		'ISIN' => [ '%13s' ],
	];

	print_header($fmt);
	print_sep($fmt);


	foreach($pf['lines'] as $line) {
		print_row($fmt, [
			'Name' => shorten_name($line['name'], 52),
			'Tkr' => $line['ticker'],
			'Cur' => $line['currency'],
			'ISIN' => $line['isin'] ?? '',
		]);
	}
}

function edit_line(array &$pf, $ticker, array $newvalues) {
	static $cols = [
		'name', 'currency', 'isin',
	];

	if(!isset($pf['lines'][$ticker])) fatal("Ticker %s not found\n", $ticker);

	foreach($cols as $c) {
		if(!isset($newvalues[$c])) continue;
		$pf['lines'][$ticker][$c] = $newvalues[$c];
	}
}

function shorten_name($name, $targetlength) {
	if(strlen($name) <= $targetlength) return $name;
	$parts = explode(' ', $name);
	$nparts = count($parts);

	do {
		$longestk = null;
		$longestl = 0;
		$length = $nparts - 1;

		foreach($parts as $k => $w) {
			$l = strlen($w);
			if($l > 3 && $l >= $longestl) {
				$longestl = $l;
				$longestk = $k;
			}
			$length += $l;
		}

		if($longestk !== null) {
			$parts[$longestk] = substr($parts[$longestk], 0, 2).'.';
			$length -= $longestl;
			$length += 3;
		}

	} while($length > $targetlength && $longestk !== null);

	return substr(implode(' ', $parts), 0, $targetlength);
}
