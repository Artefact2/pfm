<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

/* Load a portfolio. Will create an empty one if $path doesn't
 * exist. */
function load_pf($path) {
	if(!file_exists($path)) {
		notice("Creating empty portfolio at %s\n", $path);
		
		return [
			'pfm-version' => 1,
			'lines' => [],
			'tx' => [],
		];
		
	} else {
		$pf = json_decode(file_get_contents($path), true);
		if($pf === false) fatal("Could not read portfolio at %s\n", $path);

		if(!isset($pf['pfm-version'])) fatal("No version in %s, broken file?\n", $path);
		if($pf['pfm-version'] !== 1) fatal("Incompatible version %d, was expecting %d\n", $pf['pfm-version'], 1);

		uksort($pf['lines'], function($a, $b) {
			return $a <=> $b;
		});

		usort($pf['tx'], function($a, $b) {
			return $a['ts'] <=> $b['ts'];
		});
		
		return $pf;
	}
}

/* Save a portfolio. */
function save_pf(array $pf, $path) {
	$r = file_put_contents($path, json_encode($pf, JSON_PRETTY_PRINT));
	if($r === false) fatal("Could not save portfolio at %s\n", $path);
}

/* Get the default portfolio path. Can be overridden with
 * PFM_PORTFOLIO environment variable. */
function get_pf_path() {
	$pfp = getenv('PFM_PORTFOLIO');
	if($pfp !== false) return $pfp;

	$confdir = getenv('XDG_CONFIG_HOME');
	if($confdir === false) $confdir = getenv('HOME').'/.config';

	return $confdir.'/pfm.json';
}
