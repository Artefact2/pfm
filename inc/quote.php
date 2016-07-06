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
function get_quote($pf, $ticker, $date = 'now', &$from = null) {
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
		if($q !== null) {
			$from = 'brs-rt';
			return $q;
		}
			
		$q = get_yahoo_rt_quote($l['isin']);
		if($q !== null) {
			$from = 'yahoo-rt';
			return $q;
		}
	}

	$hist = get_boursorama_history($l['isin']);
	$qb = find_in_history($hist, $date, $exact);
	if($qb !== null && $exact) {
		$from = 'brs-hist exact';
		return $qb;
	}

	$hist = get_geco_amf_history($l['isin'], $date);
	$qa = find_in_history($hist, $date, $exact);
	if($qa !== null && $exact) {
		$from = 'geco-amf-hist exact';
		return $qa;
	}

	$hist = get_yahoo_history($l['isin']);
	$qy = find_in_history($hist, $date, $exact);
	if($qy !== null) {
		$from = 'yahoo-hist '.($exact ? 'exact' : '!exact');
		return $qy;
	}
	if($qa !== null) {
		$from = 'geco-amf-hist !exact';
		return $qa;
	}

	$from = 'brs-hist !exact';
	return $qb;
}

function get_boursorama_ticker($isin) {
	return get_cached_thing('brs-id-'.$isin, -31557600, function() use($isin) {
			$c = curl_init('http://www.boursorama.com/ajax/recherche/index.php?q='.$isin);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_HTTPHEADER, [
				'X-Requested-With: XMLHttpRequest',
			]);
			$r = curl_exec($c);
			if(preg_match_all('|%3Fsymbole%3D([^&]+)&|', $r, $matches)) {
				foreach($matches[1] as $tkr) {
					/* XXX: correlate currency & ticker */
					if(substr($tkr, 0, 2) === '1r') return $tkr;
				}

				return $matches[1][0];
			}
			return null;
		});
}

function get_boursorama_rt_quote($isin) {
	return get_cached_thing('brs-rt-'.$isin, -900, function() use($isin) {
			$ticker = get_boursorama_ticker($isin);
			if($ticker === null) return null;
			
			$c = curl_init(
				'http://www.boursorama.com/ajax/ui/refresh.phtml/boursorama/block/cours/orderbook?symbol='.$ticker.'&nbLines=6'
			);
			curl_setopt($c, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/41.0');
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_POST, true);
			curl_setopt($c, CURLOPT_HTTPHEADER, [
				'X-Requested-With: XMLHttpRequest',
				'X-Brs-Xhr-Request: true',
			]);
			curl_setopt($c, CURLOPT_POSTFIELDS, $q = http_build_query([
				'id' => $id = mt_rand(),
				'config[id]' => 'b'.$id,
				'config[width]' => '400',
				'config[allowCustom]' => 'false',
				'class' => 'Boursorama_Block_Cours_Orderbook',
				'parameters[symbol]' => $ticker,
				'parameters[nbLines]' => '6',
			]));
			$r = curl_exec($c);
			$d = json_decode($r, true);
			$x = new \DOMDocument();
			if(!$x->loadHTML($d['outputs']['body'])) return null;
			$x = simplexml_import_dom($x);

			$q = [ null, null ];

			for($z = 0; $z < 2; ++$z) {
				for($i = 0; $i < count($x->body->div[1]->div[$z]->table->tbody->tr); ++$i) {
					$e = $x->body->div[1]->div[$z]->table->tbody->tr[$i]->td[2 * (1 - $z)];
					if($e->span) $e = $e->span;

					$p = (float)$e;
					if($p) {
						$q[$z] = $p;
						break;
					}
				}
			}

			if($q[0] === null || $q[1] === null || $q[1] > $q[0] * 1.03) return null;
			return .5 * ($q[0] + $q[1]);
		});
}

function get_boursorama_history($isin) {
	return get_cached_thing('brs-hist-'.$isin, strtotime('tomorrow'), function() use($isin) {
			$ticker = get_boursorama_ticker($isin);
			if($ticker === null) return [];
			
			$c = curl_init(
				'http://www.boursorama.com/bourse/cours/graphiques/historique.phtml'
				.'?mo=0&form=OUI&code='.$isin
				.'&symbole='.$ticker
				.'&choix_bourse_graf=country%3A33&tc=candlestick&duree=36&pe=0&is=0&mm1=7&mm2=20&mm3=&comp=0'
				.'&indiceComp=1rPCAC&codeComp=&i1=no&i2=no&i3=no&grap=1'
			);
			curl_setopt($c, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/41.0');
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			
			if(!preg_match('%"(?<uri>/graphiques/quotes\.phtml?[^"]+)"%', curl_exec($c), $match)) return [];
			curl_setopt($c, CURLOPT_URL, 'http://www.boursorama.com'.$match['uri']);
			$j = json_decode(curl_exec($c), true);

			$hist = [];

			foreach($j['dataSets'][0]['dataProvider'] as $row) {
				$hist[(DateTime::createFromFormat('d/m/Y H:i', $row['d']))->format('Y-m-d')] = $row['c'];
			}

			return $hist;
		});
}
	
function get_yahoo_ticker($isin) {
	return get_cached_thing('yahoo-id-'.$isin, -31557600, function() use($isin) {
			$c = curl_init('https://finance.yahoo.com/lookup?s='.$isin);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
			$r = curl_exec($c);
			if(preg_match_all('%<a href="https://finance.yahoo.com/q[^?]*\?s=(?<ticker>[^"]+)"%', $r, $matches)) {
				/* XXX dirty hack */
				foreach($matches['ticker'] as $tkr) {
					if(substr($tkr, -3) === '.PA') return $tkr;
				}
				return $matches['ticker'][0];
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

			$a = floatval($m[1]);
			$b = floatval($m[3]);
			if($a > 1.03 * $b) return null;
			return .5 * ($a + $b);
		});
}

function get_yahoo_history($isin) {	
	return get_cached_thing('yahoo-hist-'.$isin, strtotime('tomorrow'), function() use($isin) {
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

function find_in_history(array $hist, $ts, &$exactdate = null) {
	if(($N = date('N', $ts)) === '6') $ts = strtotime('-1 day', $ts);
	else if($N === '7') $ts = strtotime('-2 days', $ts);
	
	$k = date('Y-m-d', $ts);
	$i = 0;
	$exactdate = true;

	for($i = 0; $i < 7; ++$i) {
		if(isset($hist[$k])) return $hist[$k];
		$k = date('Y-m-d', $ts = strtotime('-1 day', $ts));
		$exactdate = false;
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
