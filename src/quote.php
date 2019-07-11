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
 */
function get_quote(array &$pf, string $ticker, string $date = 'now'): float {
	if(!isset($pf['lines'][$ticker])) {
		fatal("Unknown ticker %s\n", $ticker);
	}
	assert(isset($pf['lines'][$ticker]['isin']));
	$isin = $pf['lines'][$ticker]['isin'];

	$date = maybe_strtotime($date);

	if(date('Y-m-d', $date) === ($today = date('Y-m-d'))) {
		$q = get_boursorama_rt_quote($isin);
		if($q !== null) return $q;
		fatal("could not find intraday quote for %s\n", $ticker);
	}

	/* XXX: refactorable? */
	$q = find_in_history($pf['hist'][$ticker] ?? [], $date);
	if($q !== null) return $q;

	$brsh = get_boursorama_history($isin);
	$bdh = get_bd_history($isin);

	$pf['hist'][$ticker] = merge_histories(
		$ticker,
		$pf['hist'][$ticker],
		merge_histories($ticker, $bdh, $brsh)
	);

	unset($pf['hist'][$ticker][$today]);
	$q = find_in_history($pf['hist'][$ticker] ?? [], $date);
	if($q !== null) return $q;

	fatal("could not find quote for %s at date %s\n", $ticker, date('Y-m-d', $date));
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
			return $d['d']['qd']['c'] ?? null;
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
				$hist[gmdate('Y-m-d', 86400 * (int)$row['d'])] = (float)$row['c'];
			}

			return $hist;
		});
}

function get_bd_id(string $isin): ?string {
	return get_cached_thing('bd-id-'.$isin, -31557600, function() use($isin): ?string {
		$c = get_curl('https://www.boursedirect.fr/api/search/'.$isin.'/lucky');
		fwrite(STDOUT, '.');
		curl_exec($c);
		$loc = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
		if(!preg_match('%/seance$%', $loc)) return null;
		return substr($loc, 0, -strlen('/seance'));
	});
}

function get_bd_history(string $isin): array {
	return get_cached_thing('bd-hist-'.$isin, strtotime('tomorrow'), function() use($isin): array {
			$loc = get_bd_id($isin);
			if($loc === null) return [];
			if(!preg_match('%-(?<ticker>[^-]+)-(?<currency>[^-]+)-(?<exchange>[^-]+)$%U', $loc, $m)) return [];

			$c = get_curl('https://www.boursedirect.fr/api/instrument/download/history/'.$m['exchange'].'/'.$m['ticker'].'/'.$m['currency']);
			fwrite(STDOUT, '.');
			$csv = curl_exec($c);
			$csv = explode("\n", trim($csv));
			array_shift($csv);
			$hist = [];
			foreach($csv as $line) {
				list($date,,,, $close) = explode(';', $line);
				if($close === '') continue;

				$hist[date('Y-m-d', strtotime(substr($date, 1, -1)))] = floatval($close);
			}
			fix_splits($isin, $hist);
			return $hist;
		});
}

function long_div(int $a, int $b) {
	return [ (int)floor($a / $b), $a % $b ];
}

function easter(int $year): string {
	/* https://fr.wikipedia.org/wiki/Calcul_de_la_date_de_P%C3%A2ques */
	assert($year >= 1583);
	list(  , $n) = long_div($year, 19);
	list($c, $u) = long_div($year, 100);
	list($s, $t) = long_div($c, 4);
	list($p,   ) = long_div($c+8, 25);
	list($q,   ) = long_div($c-$p+1, 3);
	list(  , $e) = long_div(19*$n+$c-$s-$q+15, 30);
	list($b, $d) = long_div($u, 4);
	list(  , $L) = long_div(2*$t+2*$b-$e-$d+32, 7);
	list($h,   ) = long_div($n+11*$e+22*$L, 451);
	list($m, $j) = long_div($e+$L-7*$h+114, 31);

	assert($m === 3 || $m === 4);
	return sprintf("%02d-%02d", $m, $j+1);
}

/* XXX: this depends on the exchange */
function prev_open_day(int $ts): int {
	$dow = date('N', $ts);

	if($dow === '7') {
		$ts -= 86400 * 2;
	} else if($dow === '6') {
		$ts -= 86400;
	}

	/* http://www.swingbourse.com/bourse-jours-feries.php */
	switch(date('m-d', $ts)) {
	case '01-01':
	case '05-01':
	case '12-24':
	case '12-25':
	case '12-26':
	case '12-31':
		return prev_open_day($ts - 86400);
	}

	$easter = sprintf("%04d-%s", $year = (int)date('Y', $ts), easter($year));
	if(date('Y-m-d', $ts - 86400) === $easter) {
		/* Easter monday */
		return $ts - 4 * 86400;
	} else if(date('Y-m-d', $ts + 2 * 86400) === $easter) {
		/* Holy friday */
		return $ts - 86400;
	}

	return $ts;
}

function find_in_history(array $hist, int $ts): ?float {
	$ts = prev_open_day($ts);
	return $hist[date('Y-m-d', prev_open_day($ts))] ?? null;
}

/* XXX: this is just heuristics */
function fix_splits(string $isin, array &$h): void {
	$prev = null;
	foreach($h as $k => $v) {
		if($prev === null) {
			$prev = $v;
			continue;
		}

		if($v / $prev > 3) {
			$m = round($v / $prev);
			assert(abs($v / $prev - $m) < .2);
			notice("detected merge (%.0f:1) for %s at date %s\n", $m, $isin, $k);
			$m = 1.0 / $m;
		} else if($v / $prev < .33333) {
			$m = round($prev / $v);
			assert(abs($prev / $v - $m) < .2);
			notice("detected split (1:%.0f) for %s at date %s\n", $m, $isin, $k);
		} else {
			$prev = $v;
			continue;
		}

		foreach($h as $k2 => &$v2) {
			if($k === $k2) break;
			$v2 /= $m;
		}

		$prev = $v;
	}
}

function merge_histories(string $ticker, array $h1, array $h2): array {
	$h = $h1 + $h2;
	ksort($h);
	foreach($h as $k => $v) {
		if(!isset($h1[$k]) || !isset($h2[$k])) continue;
		$delta = abs(($h2[$k] - $h1[$k]) / $h1[$k]);
		if($delta > 0.3) {
			fatal("definite history mismatch for %s at %s: %.4f ≠ %.4f\n", $ticker, $k, $h1[$k], $h2[$k]);
		} else if($delta > 0.001) {
			notice("possible history mismatch for %s at %s: %.4f ≠ %.4f\n", $ticker, $k, $h1[$k], $h2[$k]);
		}
	}
	return $h;
}
