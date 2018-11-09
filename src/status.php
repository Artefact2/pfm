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
		'Price' => [ '%9s', '%9.2f' ],
		'Quantity' => [ '%12s', '%12.4f' ],
		'Money In' => [ '%12s', '%12.2f' ],
		'Realized' => [ '%12s', '%12.2f' ],
		'Unrealized' => [ '%12s', '%12.2f' ],
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
				'Realized' => colorize_percentage(0, '%12.2f', null, null, null, null, $a['realized']),
			]);
			continue;
		}

		print_row($fmt, [
			'Tkr' => (string)$tkr,
			'%Wgt' => 100.0 * $a['value'] / $totals['value'],
			'Price' => $a['price'],
			'Quantity' => $a['qty'],
			'Money In' => $a['in'] - $a['out'],
			'Realized' => colorize_percentage(
				100.0 * $a['realized'] / $a['value'], '%12.2f',
				null, null, null, null, $a['realized']
			),
			'Unrealized' => colorize_percentage(
				100.0 * $a['unrealized'] / $a['value'], '%12.2f',
				null, null, null, null, $a['unrealized']
			),
		]);
	}

    print_sep($fmt);

	print_row($fmt, [
		'Tkr' => 'TOT',
		'Money In' => $totals['in'] - $totals['out'],
		'Realized' => colorize_percentage(
			100.0 * $totals['realized'] / $totals['in'], '%12.2f',
			null, null, null, null, $totals['realized']
		),
		'Unrealized' => colorize_percentage(
			100.0 * $totals['unrealized'] / $totals['in'], '%12.2f',
			null, null, null, null, $totals['unrealized']
		),
	]);
}

function perf(array &$pf, $date = 'now', $columns = 'default') {
	$ts = maybe_strtotime($date);

	$fmt = [
		'Ticker' => [ '%8s' ],
	];

	switch($columns) {

	case 'default':
		$startday = strtotime('yesterday', $ts);
		$periods[] = [
			'Day', $startday, $ts, '%7.2f', '%7s'
		];

		$periods[] = [
			'WtD', strtotime('last sunday', $ts), $ts, '%5.1f', '%5s'
		];

		$startmonth = strtotime('last day of last month', $ts);
		$periods[] = [
			'MtD', $startmonth, $ts, '%5.1f', '%5s'
		];

		$startyear = strtotime('-1 year', strtotime(date('Y-12-31', $ts)));
		$periods[] = [
			'YtD', $startyear, $ts, '%5.1f', '%5s'
		];

		for($i = 0; $i < 3; ++$i) {
			$prevmonth = strtotime('last day of last month', $startmonth);
			$periods[] = [
				date('M', $startmonth), $prevmonth, $startmonth, '%5.1f', '%5s'
			];
			$startmonth = $prevmonth;
		}

		for($i = 0; $i < 3; ++$i) {
			$prevyear = strtotime('-1 year', $startyear);
			$periods[] = [
				date('Y', $startyear), $prevyear, $startyear, '%5.1f', '%5s'
			];
			$startyear = $prevyear;
		}
		break;

	case 'days':
		$start = strtotime('yesterday', $ts);
		$periods[] = [
			date('W-N', $ts), $start, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prev = strtotime('yesterday', $start);
			if(in_array(date('N', $start), [ '6', '7' ], true)) {
				--$i;
			} else {
				$periods[] = [
					date('W-N', $start), $prev, $start, '%5.1f', '%5s'
				];
			}
			$start = $prev;
		}
		break;

	case 'weeks':
		$start = strtotime(date('Y-m-d', strtotime('last Sunday', $ts)));
		$periods[] = [
			'WtD', $start, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prev = strtotime('last Sunday', $start);
			$periods[] = [
				date('\WW', $start), $prev, $start, '%5.1f', '%5s'
			];
			$start = $prev;
		}
		break;

	case 'months':
		$startmonth = strtotime('last day of last month', $ts);
		$periods[] = [
			'MtD', $startmonth, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prevmonth = strtotime('last day of last month', $startmonth);
			$periods[] = [
				date('M', $startmonth), $prevmonth, $startmonth, '%5.1f', '%5s'
			];
			$startmonth = $prevmonth;
		}
		break;

	case 'years':
		$startyear = strtotime('-1 year', strtotime(date('Y-12-31', $ts)));
		$periods[] = [
			'YtD', $startyear, $ts, '%7.2f', '%7s'
		];

		for($i = 0; $i < 9; ++$i) {
			$prevyear = strtotime('-1 year', $startyear);
			$periods[] = [
				date('Y', $prevyear), $prevyear, $startyear, '%5.1f', '%5s'
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

	foreach($periods as $i => $p) {
		list($k, $start, $end) = $p;

		$irra = irr($pf, $start, $end);

		foreach($irra as $tkr => $irr) {
			$pc = colorize_percentage(100.0 * ($irr - 1.0), $i === 0 ? '%7.2f' : '%5.1f');

			if($tkr === '__total__') {
				$ftotal[$k] = $pc;
			} else {
				$ftable[$tkr][$k] = $pc;
			}
		}
	}

	foreach($ftable as $ticker => $row) {
		$row['Ticker'] = (string)$ticker;
		print_row($fmt, $row);
	}

	print_sep($fmt);
	print_row($fmt, $ftotal);
}
