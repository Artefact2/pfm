<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

/* Get a quote for a given stock.
 *
 * @param $date a strtotime()-parseable date. If 'now', a real time
 * quote will be used. If not, the closing price of the day will be
 * fetched.
 * @returns a price or null if no price could be fetched
 */
function get_quote(array &$pf, string $ticker, string $date = 'now'): ?float {
	if(!isset($pf['lines'][$ticker])) {
		fatal("Unknown ticker %s\n", $ticker);
	}
	assert(isset($pf['lines'][$ticker]['isin']));
	$isin = $pf['lines'][$ticker]['isin'];

	$date = maybe_strtotime($date);

	if(date('Y-m-d', $date) === date('Y-m-d')) {
		return get_boursorama_rt_quote($isin);
	}

	$q = find_in_history($pf['hist'][$ticker] ?? [], $date);
	if($q !== null) return $q;

	$hist = get_boursorama_history($isin);
	foreach($hist as $k => $v) {
		$pf['hist'][$ticker][$k] = $v;
	}
	return find_in_history($pf['hist'][$ticker] ?? [], $date);
}

function get_curl(string $url) {
	$c = curl_init($url);
	curl_setopt($c, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/41.0');
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
	//curl_setopt($c, CURLOPT_VERBOSE, true);
	return $c;
}

function get_boursorama_ticker(string $isin): ?string {
	return get_cached_thing('brs-id-'.$isin, -31557600, function() use($isin) {
			$c = get_curl('https://www.boursorama.com/recherche/ajax?query='.$isin);
			curl_setopt($c, CURLOPT_HTTPHEADER, [
				'X-Requested-With: XMLHttpRequest',
			]);
			fwrite(STDOUT, '.');
			$r = curl_exec($c);
			if(!preg_match_all('%href="/bourse/[^/]+/cours/([^/]+)/"%', $r, $matches)) return null;
			foreach($matches[1] as $tkr) {
				/* XXX: correlate currency & ticker */
				if(substr($tkr, 0, 2) === '1r') return $tkr;
			}
			return $matches[1][0];
		});
}

function get_boursorama_rt_quote($isin): ?float {
	return get_cached_thing('brs-rt-'.$isin, -900, function() use($isin): ?float {
			$ticker = get_boursorama_ticker($isin);
			if($ticker === null) return null;

			$c = get_curl('https://www.boursorama.com/bourse/action/graph/ws/GetTicksEOD?'.http_build_query([
				'symbol' => $ticker,
				'length' => 5,
				'period' => 1,
				'guid' => '',
			]));
			curl_setopt($c, CURLOPT_HTTPHEADER, [
				'X-Requested-With: XMLHttpRequest',
			]);
			fwrite(STDOUT, '.');
			$r = curl_exec($c);
			$d = json_decode($r, true);
			return $d['d']['qd']['o'] ?? null;
		});
}

function get_boursorama_history(string $isin): array {
	return get_cached_thing('brs-hist-'.$isin, strtotime('tomorrow'), function() use($isin): array {
			$ticker = get_boursorama_ticker($isin);
			if($ticker === null) return [];

			$c = get_curl('https://www.boursorama.com/bourse/action/graph/ws/GetTicksEOD?'.http_build_query([
				'symbol' => $ticker,
				'length' => 7300,
				'period' => 0,
				'guid' => '',
			]));
			curl_setopt($c, CURLOPT_HTTPHEADER, [
				'X-Requested-With: XMLHttpRequest',
			]);
			fwrite(STDOUT, '.');
			$r = curl_exec($c);
			$d = json_decode($r, true);

			foreach($d['d']['QuoteTab'] as $row) {
				$hist[gmdate('Y-m-d', 86400 * (int)$row['d'])] = (float)$row['l'];
			}

			return $hist;
		});
}

function find_in_history(array $hist, int $ts): ?float {
	for($i = 0; $i < 7; ++$i) {
		$k = date('Y-m-d', $ts);
		if(isset($hist[$k])) return $hist[$k];
		$ts = strtotime('-1 day', $ts);
	}

	return null;
}
