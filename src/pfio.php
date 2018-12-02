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
function save_pf(array $pf, string $path): void {
	$r = file_put_contents($path, json_encode($pf, JSON_PRETTY_PRINT));
	if($r === false) fatal("Could not save portfolio at %s\n", $path);
}

/* Get the default portfolio path. Can be overridden with
 * PFM_PORTFOLIO_FILE environment variable. */
function get_pf_path(): string {
	$pfp = getenv('PFM_PORTFOLIO_FILE');
	if($pfp !== false) return $pfp;

	return get_paths()['data-home'].'/pfm.json';
}

function getenv_fb(string $name, string $fallback): string {
	$val = getenv($name);
	return ($val !== false) ? $val : $fallback;
}

function get_paths(): array {
	static $paths = [];
	if($paths !== []) return $paths;
	$paths['data-home'] = getenv_fb('XDG_DATA_HOME', getenv('HOME').'/.local/share').'/pfm';
	$paths['cache-home'] = getenv_fb('XDG_CACHE_HOME', getenv('HOME').'/.cache').'/pfm';
	$paths['configs'] = explode(':', getenv_fb('XDG_CONFIG_DIRS', '/etc/xdg'));
	array_unshift($paths['configs'], getenv_fb('XDG_CONFIG_HOME', getenv('HOME').'/.config'));
	foreach($paths['configs'] as &$p) $p .= '/pfm';
	unset($p);
	return $paths;
}

function get_config(): array {
	static $conf = null;
	if($conf !== null) return $conf;

	$paths = get_paths()['configs'];
	foreach($paths as $p) {
		if(file_exists($f = $p.'/pfm.ini') && is_readable($f)) {
			return $conf = parse_ini_file($f);
		}
	}

	fatal("No configuration file found anywhere, please put pfm.ini in one of the following directories: %s\n", implode(', ', $paths));
}
