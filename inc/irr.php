<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function irr(array $pf, $start, $end) {
	$start = maybe_strtotime($start);
	$end = maybe_strtotime($end);

	$flows = [];
	
	foreach(iterate_time($pf, $start, $end) as $ts => $data) {
		$t = ($ts - $start) / ($end - $start);
		
		if($ts === $start || $ts === $end) {
			$tval = 0.0;

			foreach($data['agg'] as $ticker => $tdata) {
				if($tdata['qty']) {
					$tval += $tdata['qty'] * get_quote($pf, $ticker, $ts);
				}
			}

			$flows[] = [
				$t,
				$ts === $start ? $tval : - $tval,
			];
			
			continue;
		}

		$dnav = $data['dtotals']['in']
			- $data['dtotals']['out']
			+ $data['dtotals']['realized'];

		if($dnav) {
			$flows[] = [ $t, $dnav ];
		}
	}

	$npv = function($r) use($flows) {
		$sum = 0.0;

		foreach($flows as $d) {
			$sum += $d[1] / pow($r, $d[0]);
		}

		return $sum;
	};

	$r0 = .5;
	$npv0 = $npv($r0);
	$r1 = 2;
	$npv1 = $npv($r1);
	
	while(abs($npv1) > 1e-5) {
		/* Secant method, stolen from Wikipedia */
		$newr = $r1 - $npv1 * ($r1 - $r0) / ($npv1 - $npv0);
		$newnpv = $npv($newr);

		$r0 = $r1;
		$npv0 = $npv1;
		$r1 = $newr;
		$npv1 = $newnpv;
	}

	return $r1;
}
