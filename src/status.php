<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function status(array &$pf, $date = 'now') {
	static $fmt = [
		'Tkr' => [ '%5s' ],
		'%Wgt' => [ '%6s', '%6.2f' ],
		'Price' => [ '%10s', '%10.2f' ],
		'Quantity' => [ '%13s', '%13.4f' ],
		'Avg price' => [ '%12s', '%12.5f' ],
		'Exposure' => [ '%11s', '%11.0f' ],
		'Total P/L' => [ '%11s', '%11.0f' ],
	];

	print_header($fmt);
	print_sep($fmt);

	$totals = [];
	$agg = aggregate_tx($pf, [
		'before' => $date,
	], $totals);

	$totals['value'] = 0.0;
	$totals['unrealized'] = 0.0;

	foreach($agg as $tkr => &$a) {
		if(!$a['qty']) {
			$a['value'] = 0;
			continue;
		}

		$a['price'] = get_quote($pf, $tkr, $date);
		$totals['value'] += $a['value'] = $a['price'] * $a['qty'];
		$totals['unrealized'] += $a['unrealized'] = $a['value'] - $a['in'] + $a['out'];
	}
	unset($a);

	uasort($agg, function($a, $b) {
		if($a['qty'] && $b['qty']) return $b['value'] <=> $a['value'];
		if($a['qty'] && !$b['qty']) return -1;
		if(!$a['qty'] && $b['qty']) return 1;
		return $b['realized'] <=> $a['realized'];
	});

	foreach($agg as $tkr => $a) {
		if(!$a['qty']) {
			print_row($fmt, [
				'Tkr' => (string)$tkr,
				'Total P/L' => colorize_percentage(0, $fmt['Total P/L'][1], null, null, null, null, $a['realized']),
			]);
			continue;
		}

		print_row($fmt, [
			'Tkr' => (string)$tkr,
			'%Wgt' => 100.0 * $a['value'] / $totals['value'],
			'Price' => $a['price'],
			'Quantity' => $a['qty'],
			'Avg price' => ($a['in'] - $a['out']) / $a['qty'],
			'Exposure' => colorize_percentage(0, $fmt['Exposure'][1], null, null, null, null, $a['value']),
			'Total P/L' => colorize_percentage(
				100.0 * ($a['realized'] + $a['unrealized']) / $a['value'],
				$fmt['Total P/L'][1],
				null, null, null, null,
				$a['realized'] + $a['unrealized']
			),
		]);
	}

    print_sep($fmt);

	print_row($fmt, [
		'Tkr' => 'TOT',
		'Exposure' => colorize_percentage(
			0, $fmt['Exposure'][1],
			null, null, null, null,
			$totals['value']
		),
		'Total P/L' => colorize_percentage(
			100.0 * ($totals['realized'] + $totals['unrealized']) / $totals['value'],
			$fmt['Total P/L'][1],
			null, null, null, null,
			$totals['realized'] + $totals['unrealized']
		),
	]);
}

function perf(array &$pf, $type = 'irr', $date = 'now', $columns = 'default', $rows = 'all', $sort = 'perf') {
	$ts = maybe_strtotime($date);

	$fmt = [
		'Ticker' => [ '%8s' ],
	];

	if($type === 'irr') {
		$fcolfmt = [ '%7s', '%7.2f' ];
		$colfmt = [ '%5s', '%5.1f' ];
		$extracols = 9;
	} else if($type === 'pnl') {
		$fcolfmt = $colfmt = [ '%10s', '%10.0f' ];
		$extracols = 5;
	} else {
		fatal("invalid value for type, expected irr or pnl\n");
	}

	switch($columns) {

	case 'default':
		$startday = strtotime('yesterday', $ts);
		$periods[] = [
			'Day', $startday, $ts, $fcolfmt[1], $fcolfmt[0]
		];

		$periods[] = [
			'WtD', strtotime('last sunday', $ts), $ts, $colfmt[1], $colfmt[0]
		];

		$startmonth = strtotime('last day of last month', $ts);
		$periods[] = [
			'MtD', $startmonth, $ts, $colfmt[1], $colfmt[0]
		];

		$startyear = strtotime('-1 year', strtotime(date('Y-12-31', $ts)));
		$periods[] = [
			'YtD', $startyear, $ts, $colfmt[1], $colfmt[0]
		];

		if($extracols - 3 >= 4) {
			$nmonths = floor(($extracols - 3) / 2);
			$nyears = ceil(($extracols - 3) / 2);
		} else {
			$nmonths = 0;
			$nyears = $extracols - 3;
		}

		for($i = 0; $i < $nmonths; ++$i) {
			$prevmonth = strtotime('last day of last month', $startmonth);
			$periods[] = [
				date('M', $startmonth), $prevmonth, $startmonth, $colfmt[1], $colfmt[0]
			];
			$startmonth = $prevmonth;
		}

		for($i = 0; $i < $nyears; ++$i) {
			$prevyear = strtotime('-1 year', $startyear);
			$periods[] = [
				date('Y', $startyear), $prevyear, $startyear, $colfmt[1], $colfmt[0]
			];
			$startyear = $prevyear;
		}
		break;

	case 'days':
		$start = strtotime('yesterday', $ts);
		$periods[] = [
			date('W-N', $ts), $start, $ts, $fcolfmt[1], $fcolfmt[0]
		];

		for($i = 0; $i < $extracols; ++$i) {
			$prev = strtotime('yesterday', $start);
			if(in_array(date('N', $start), [ '6', '7' ], true)) {
				--$i;
			} else {
				$periods[] = [
					date('W-N', $start), $prev, $start, $colfmt[1], $colfmt[0]
				];
			}
			$start = $prev;
		}
		break;

	case 'weeks':
		$start = strtotime(date('Y-m-d', strtotime('last Sunday', $ts)));
		$periods[] = [
			'WtD', $start, $ts, $fcolfmt[1], $fcolfmt[0]
		];

		for($i = 0; $i < $extracols; ++$i) {
			$prev = strtotime('last Sunday', $start);
			$periods[] = [
				date('\WW', $start), $prev, $start, $colfmt[1], $colfmt[0]
			];
			$start = $prev;
		}
		break;

	case 'months':
		$startmonth = strtotime('last day of last month', $ts);
		$periods[] = [
			'MtD', $startmonth, $ts, $fcolfmt[1], $fcolfmt[0]
		];

		for($i = 0; $i < $extracols; ++$i) {
			$prevmonth = strtotime('last day of last month', $startmonth);
			$periods[] = [
				date('M', $startmonth), $prevmonth, $startmonth, $colfmt[1], $colfmt[0]
			];
			$startmonth = $prevmonth;
		}
		break;

	case 'years':
		$startyear = strtotime('-1 year', strtotime(date('Y-12-31', $ts)));
		$periods[] = [
			'YtD', $startyear, $ts, $fcolfmt[1], $fcolfmt[0]
		];

		for($i = 0; $i < $extracols; ++$i) {
			$prevyear = strtotime('-1 year', $startyear);
			$periods[] = [
				date('Y', $prevyear), $prevyear, $startyear, $colfmt[1], $colfmt[0]
			];
			$startyear = $prevyear;
		}
		break;

	default:
		fatal("perf(): unknown column type %s\n", $columns);
		break;

	}

	foreach($periods as $p) {
		$fmt[$p[0]] = [
			$p[4], $p[4]
		];
	}

	print_header($fmt);
	print_sep($fmt);

	$ftable = [];
	$ftotal = [ 'Ticker' => 'TOT' ];
	$sortdata = [];

	foreach($periods as $i => $p) {
		list($k, $start, $end, $valfmt) = $p;

		if($type === 'irr') {
			foreach(irr($pf, $start, $end) as $tkr => $irr) {
				$pc = colorize_percentage(100.0 * ($irr - 1.0), $valfmt);

				if($tkr === '__total__') {
					$ftotal[$k] = $pc;
				} else {
					$ftable[$tkr][$k] = $pc;
					$sortdata[$tkr][$k] = $irr;
				}
			}
		} else {
			$iteratorLast = function(\Traversable $i) {
				foreach($i as $val);
				return $val;
			};

			$agg = $iteratorLast(iterate_time($pf, $start, $end, '+'.($end - $start).' seconds'));
			$totalpl = 0;
			$totalbasis = 0;

			foreach($agg['agg'] as $tkr => $a) {
				if($a['qty'] < 1e-5 && !isset($agg['delta'][$tkr])) continue;
				$delta = $agg['delta'][$tkr] ?? [ 'realized' => 0, 'qty' => 0, 'in' => 0, 'out' => 0 ];

				$pl = $delta['realized'];
				if($a['qty'] > 1e-5) {
					$pl += get_quote($pf, $tkr, $end) * $a['qty'] - ($a['in'] - $a['out']);
				} else assert($a['in'] - $a['out'] < 1e-5);
				if($a['qty'] - $delta['qty'] > 1e-5) {
					$pl -= (get_quote($pf, $tkr, $start) * ($a['qty'] - $delta['qty']) - ($a['in'] - $delta['in'] - ($a['out'] - $delta['out'])));
				} else assert($a['in'] - $delta['in'] - ($a['out'] - $delta['out']) < 1e-5);

				$ftable[$tkr][$k] = colorize_percentage(
					($a['in'] - $a['out']) > 1e-5 ? (100.0 * $pl / ($a['in'] - $a['out'])) : 1.0,
					$valfmt,
					null, null, null, null, $pl
				);
				$sortdata[$tkr][$k] = $pl;

				$totalpl += $pl;
				$totalbasis += $a['in'] - $a['out'];
			}

			if($totalpl !== 0) {
				$ftotal[$k] = colorize_percentage(
					$totalbasis > 0 ? (100.0 * $totalpl / $totalbasis) : 1.0,
					$valfmt,
					null, null, null, null, $totalpl
				);
			}
		}
	}

	$agg = aggregate_tx($pf, [ 'before' => $date ]);

	if($sort === 'perf') {
		uksort($ftable, function($t1, $t2) use($sortdata) {
			foreach($sortdata[$t1] as $k => $v1) {
				if(!isset($sortdata[$t2][$k])) {
					return -1;
				}+
					 $v2 = $sortdata[$t2][$k];
				if($v1 - $v2 > 0.001) return -1;
				if($v2 - $v1 > 0.001) return 1;
			}

			return strcmp($t1, $t2);
		});
	} else if($sort === 'weight') {
		uksort($ftable, function($t1, $t2) use($pf, $date, $agg) {
			$q1 = isset($agg[$t1]) ? $agg[$t1]['qty'] : 0;
			$q2 = isset($agg[$t2]) ? $agg[$t2]['qty'] : 0;
			if($q1 < 1e-5) return 1;
			if($q2 < 1e-5) return -1;
			return get_quote($pf, $t2, $date) * $q2 - get_quote($pf, $t1, $date) * $q1;
		});
	} else if($sort === 'ticker') {
		ksort($ftable);
	} else {
		fatal("invalid value for sort, expected perf, weight or ticker\n");
	}

	foreach($ftable as $ticker => $row) {
		if($rows === 'all'
		   || ($rows === 'open' && $agg[$ticker]['qty'] > 0)
		   || preg_match('%(^|,)'.preg_quote($ticker, '%').'(,|$)%', $rows)) {
			$row['Ticker'] = (string)$ticker;
			print_row($fmt, $row);
		}
	}

	print_sep($fmt);
	print_row($fmt, $ftotal);
}
