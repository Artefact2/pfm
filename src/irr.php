<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function irr(array &$pf, $start, $end) {
	$start = maybe_strtotime($start);
	$end = maybe_strtotime($end);

	$flows = [ '__total__' => [] ];

	foreach(iterate_time($pf, $start, $end) as $ts => $data) {
		$t = ($ts - $start) / ($end - $start);

		if($ts === $start || $ts === $end) {
			$tval = 0.0;

			foreach($data['agg'] as $ticker => $tdata) {
				if(!$tdata['qty']) continue;

				$tval += $val = $tdata['qty'] * get_quote($pf, $ticker, $ts);
				$flows[$ticker][] = [
					$t,
					$ts === $start ? $val : -$val,
				];
			}

			$flows['__total__'][] = [
				$t,
				$ts === $start ? $tval : -$tval,
			];

			continue;
		}

		$tdnav = 0;

		foreach($data['delta'] as $tkr => $delta) {
			$tdnav += $dnav = $delta['in'] - $delta['out'] - $delta['realized'];

			if($dnav) {
				if(!isset($flows[$tkr])) {
					$flows[$tkr] = [];
				}

				$flows[$tkr][] = [ $t, $dnav ];
			}
		}

		if($tdnav) {
			$flows['__total__'][] = [ $t, $tdnav ];
		}
	}

	foreach($flows as &$f) {
		$c = count($f);
		assert($c >= 2);

		--$c;

		$t0 = $f[0][0];
		$t1 = $f[$c][0];

		for($i = 1; $i < $c; ++$i) {
			$f[$i][0] = ($f[$i][0] - $t0) / ($t1 - $t0);
		}

		$f[0][0] = 0.0;
		$f[$c][0] = 1.0;
	}
	unset($f);

	$npv = function($r, $ticker) use($flows) {
		$sum = 0.0;

		foreach($flows[$ticker] as $d) {
			$sum += $d[1] / pow($r, $d[0]);
		}

		return $sum;
	};

	$ret = [];

	foreach($flows as $tkr => $f) {
		if(count($f) == 2 && $f[0][1] < 1e-2 && $f[1][1] < 1e-2) {
			$ret[$tkr] = 1.0;
			continue;
		}

		$l = 0.0;
		$u = 1000.0; /* XXX */

		while($u - $l > 1e-5) {
			$x = ($l + $u) * .5;
			if($npv($x, $tkr) > 0) {
				$u = $x;
			} else {
				$l = $x;
			}
		}

		$ret[$tkr] = $l;
	}

	return $ret;
}
