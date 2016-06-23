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
function get_quote($pf, $ticker, $date = 'now') {
	$cachedir = getenv('XDG_CACHE_HOME');
	if($cachedir === false) $cachedir = getenv('HOME').'/.cache';

	if(!isset($pf['lines'][$ticker])) {
		fatal("Unknown ticker %s\n", $ticker);
	}
	$l = $pf['lines'][$ticker];

	if(!isset($l['isin'])) return null;
	
	$date = maybe_strtotime($date);

	if(date('Y-m-d', $date) === date('Y-m-d')) {
		$q = get_boursorama_rt_quote($l['isin']);
		if($q !== null) return $q;
			
		$q = get_yahoo_rt_quote($l['isin']);
		if($q !== null) return $q;
	}

	$hist = get_geco_amf_history($l['isin'], $date);
	$q = find_in_history($hist, $date);
	if($q !== null) return $q;

	$hist = get_yahoo_history($l['isin']);
	$q = find_in_history($hist, $date);
	if($q !== null) return $q;
}

function get_boursorama_token() {
	return get_cached_thing('brs-token', -300, function() {
		$c = curl_init('http://www.boursorama.com/');
		curl_setopt($c, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/41.0');
		curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
		$r = curl_exec($c);
		preg_match('%BRS\.App\.Streaming\.Proxy\.Ajax\(\{token: \'(?<token>[^\']+?)\'%', $r, $matches);
		return $matches['token'] ?? null;
	});
}

function get_boursorama_ticker($isin) {
	return get_cached_thing('brs-id-'.$isin, -31557600, function() use($isin) {
			$c = curl_init('http://www.boursorama.com/recherche/?q='.$isin);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
			curl_exec($c);
			$url = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
			if(preg_match('%\?symbole=(.+)$%', $url, $match)) {
				return $match[1];
			}
			return null;
		});
}

function get_boursorama_rt_quote($isin) {
	return get_cached_thing('brs-rt-'.$isin, -900, function() use($isin) {
			$tok = get_boursorama_token();
			if($tok === null) return null;

			$ticker = get_boursorama_ticker($isin);
			if($ticker === null) return null;
			
			$c = curl_init('http://www.boursorama.com/flux/streaming.phtml');
			curl_setopt($c, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/41.0');
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_POST, true);
			curl_setopt($c, CURLOPT_POSTFIELDS, $q = http_build_query([
				'token' => $tok,
				//'symboles['.$ticker.'][live]' => 'R',
				'symboles['.$ticker.'][book]' => 'D',
			]));
			$r = curl_exec($c);
			$d = json_decode($r, true);
			if(!isset($d['result'][$ticker]['book'][0])) return null;
			return .5 * (
				floatval($d['result'][$ticker]['book'][0]['ask']) +
				floatval($d['result'][$ticker]['book'][0]['bid'])
			);
		});
}
	
function get_yahoo_ticker($isin) {
	return get_cached_thing('yahoo-id-'.$isin, -31557600, function() use($isin) {
			$c = curl_init('https://finance.yahoo.com/lookup?s='.$isin);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
			$r = curl_exec($c);
			if(preg_match('%<a href="https://finance.yahoo.com/q[^?]*\?s=(?<ticker>[^"]+)"%', $r, $match)) {
				/* XXX: may not return the *best* one */
				return $match['ticker'];
			}
			return null;
		});
}

function get_yahoo_rt_quote($isin) {
	return get_cached_thing('yahoo-rt-'.$isin, -900, function() use($isin) {
			$yahooticker = get_yahoo_ticker($isin);
			if($yahooticker === null) return null;
			$price = @file_get_contents(sprintf('https://download.finance.yahoo.com/d/quotes.csv?s=%s&f=abl', $yahooticker));
			if($price === false || !preg_match('%^([0-9]+(\.[0-9]+)?),([0-9]+(\.[0-9]+)?)$%m', $price, $m)) {
				return null;
			}

			return .5 * (floatval($m[1]) + floatval($m[3]));
		});
}

function get_yahoo_history($isin) {
	static $tomorrow = null;
	if($tomorrow === null) {
		$tomorrow = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00')));
	}
	
	return get_cached_thing('yahoo-hist-'.$isin, $tomorrow, function() use($isin) {
			$yahooticker = get_yahoo_ticker($isin);
			if($yahooticker === null) return [];
			$csv = @file_get_contents($url = sprintf('https://ichart.finance.yahoo.com/table.csv?s=%s&c=1900', $yahooticker));
			if($csv === false) {
				return [];
			}
			$csv = parse_crude_csv($csv);
			$hist = [];
			foreach($csv as $line) {
				$hist[date('Y-m-d', strtotime($line['Date']))] = $line['Close'];
			}
			ksort($hist);
			return $hist;
		});
}

function find_in_history(array $hist, $ts) {
	$k = date('Y-m-d', $ts);
	$i = 0;

	for($i = 0; $i < 7; ++$i) {
		if(isset($hist[$k])) return $hist[$k];
		$k = date('Y-m-d', $ts = strtotime('-1 day', $ts));
	}

	return null;
}

/* Parse CSV data. Does not support escaping of any kind. Assumes the
 * first line are column names. */
function parse_crude_csv($csv) {
	$a = [];
	$keys = null;
	
	foreach(explode("\n", $csv) as $line) {
		if($line === '') continue;
		
		if($keys === null) {
			$keys = explode(',', $line);
		} else {
			$a[] = array_combine($keys, explode(',', $line));
		}
	}

	return $a;
}

function get_geco_amf_id($isin) {
	return get_cached_thing('geco-id-'.$isin, -31557600, function() use($isin) {
			$c = curl_init(sprintf(
				'http://geco.amf-france.org/bio/rech_part.aspx'
				.'?varvalidform=on&CodeISIN=%s'
				.'&CLASSPROD=0&NumAgr=&selectNRJ=0&NomProd=&NomSOc='
				.'&action=new&valid_form=Lancer+la+recherche',
				$isin
			));
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
			curl_exec($c);
			$url = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
			if(preg_match('%\?NumProd=([1-9][0-9]*?)&NumPart=([1-9][0-9]*?)$%', $url, $match)) {
				return [
					'NumProd' => $match[1],
					'NumPart' => $match[2],
				];
			}

			return null;
		});
}

function get_geco_amf_history($isin, $ts) {
	static $tomorrow = null;
	if($tomorrow === null) {
		$tomorrow = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00')));
	}
	
	return get_cached_thing(
		'geco-'.$isin.'-'.date('Y-W', $ts),
		time() - $ts > 604800 ? -31557600 : $tomorrow,
		function() use($isin, $ts) {
			$ts = strtotime(date('Y-m-d', $ts));
			$amfid = get_geco_amf_id($isin);
			if($amfid === null) return null;
			
			$c = curl_init(sprintf(
				'http://geco.amf-france.org/bio/info_part.aspx'
				.'?SEC=VL'
				.'&NumProd=%s&NumPart=%s'
				.'&DateDeb=%s&DateFin=%s&btnvalid=OK',
				$amfid['NumProd'],
				$amfid['NumPart'],
				date('d%2\Fm%2\FY', strtotime('-7 days', $ts)),
				date('d%2\Fm%2\FY', strtotime('+7 days', $ts))
			));
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
			$hist = curl_exec($c);
			preg_match_all('%<tr class=\'ligne[12]\'>.+?[^0-9]([0-9]{2})/([0-9]{2})/([0-9]{4})[^0-9].+?[^0-9]([0-9]+,[0-9]+)[^0-9].+?</tr>%U', $hist, $matches);

			$hist = [];
			foreach($matches[0] as $k => $d) {
				$date = $matches[3][$k].'-'.$matches[2][$k].'-'.$matches[1][$k];
				$val = floatval(strtr($matches[4][$k], ',', '.'));
				$hist[$date] = $val;
			}

			ksort($hist);
			return $hist;
		});
}
