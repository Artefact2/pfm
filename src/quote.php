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

	if(date('Y-m-d', $date) === ($today = date('Y-m-d'))) {
		return get_boursorama_rt_quote($isin);
	}

	$q = find_in_history($pf['hist'][$ticker] ?? [], $date);
	if($q !== null) return $q;

	$hist = get_boursorama_history($isin);
	foreach($hist as $k => $v) {
		$pf['hist'][$ticker][$k] = $v;
	}
	unset($pf['hist'][$ticker][$today]);
	$q = find_in_history($pf['hist'][$ticker] ?? [], $date);
	if($q !== null) return $q;

	/* XXX: refactor me */
	$hist = get_quantalys_hist($isin);
	foreach($hist as $k => $v) {
		$pf['hist'][$ticker][$k] = $v;
	}
	unset($pf['hist'][$ticker][$today]);
	$q = find_in_history($pf['hist'][$ticker] ?? [], $date);
	if($q !== null) return $q;
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

function get_quantalys_id(string $isin): int {
	return get_cached_thing('quantalys-id-'.$isin, -31557600, function() use($isin): ?int {
			$c = get_curl('https://www.quantalys.com/Recherche/RechercheRapide');
			curl_setopt($c, CURLOPT_POSTFIELDS, [ 'inputSearch' => $isin ]);
			if(curl_exec($c) === false) return null;
			$uri = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
			if(!preg_match('%^https://www\.quantalys\.com/Fonds/(?<id>[0-9]+)$%', $uri, $m)) return null;
			return (int)$m['id'];
		});
}

function get_quantalys_hist(string $isin): array {
	return get_cached_thing('quantalys-hist-'.$isin, strtotime('tomorrow'), function() use($isin): array {
			$id = get_quantalys_id($isin);
			if($id === null) return [];

			$c = get_curl('https://www.quantalys.com/Fonds/GetDefaultCourbes');
			curl_setopt($c, CURLOPT_POSTFIELDS, [ 'ID_Produit' => $id ]);
			$json = curl_exec($c);
			if($json === false || ($json = json_decode($json, true)) === false) return [];
			assert($json['list']['Data'][0]['ID'] === $id);

			$c = get_curl('https://www.quantalys.com/Fonds/GetChartHisto_Historique');
			curl_setopt($c, CURLOPT_POSTFIELDS, [
				'ID_Produit' => $id,
				'jsonListeCourbes' => json_encode([
					[
						'ID' => $id,
						'Nom' => urlencode($json['list']['Data'][0]['Nom']),
						'Type' => 1,
						'Color' => '#0A50A1',
						'FinancialItem' => [
							'ID_Produit' => $id,
							'cTypeFinancialItem' => 1,
							'cClasseFinancialItem' => 0,
							'nModeCalcul' => 1,
						]
					]
				]),
				'sDtEnd' => $json['dtEnd'],
				'sDtStart' => $json['dtStart'],
			]);
			$json = curl_exec($c);
			if($json === false || ($json = json_decode($json, true)) === false) return [];

			$c = get_curl('https://www.quantalys.com/Fonds/'.$id);
			$html = curl_exec($c);
			if($html === false) return [];
			if(!preg_match('%<span Class="vl-box-value">\s*(?<vl>[0-9,]+)\s+(?<cur>[A-Z]+)\s*</span>%', $html, $mvl)) return false;
			if(!preg_match('%<span Class="vl-box-date">\s*(?<date>[0-9/]+)\s*</span>%', $html, $md)) return false;

			$vl = floatval(str_replace(',', '.', $mvl['vl']));
			$vld = explode('/', $md['date']);
			$vld = gmdate('Y-m-d', gmmktime(0, 0, 0, (int)$vld[1], (int)$vld[0], (int)$vld[2]));

			$hist = [];
			$data = json_decode($json['graph'], true)['dataProvider'];
			foreach($data as $v) {
				$hist[gmdate('Y-m-d', strtotime($v['x']))] = floatval($v['y_0']);
			}

			assert(isset($hist[$vld]));
			$factor = $vl / $hist[$vld];
			foreach($hist as $k => &$v) {
				$v = round($factor * $v, 2);
			}

			return $hist;
		});
}
