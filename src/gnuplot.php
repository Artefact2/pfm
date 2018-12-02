<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function tsv_gains(array $pf, $stream, $start, $end) {
	$start = strtotime(date('Y-m-d', maybe_strtotime($start)));
	$end = strtotime(date('Y-m-d', maybe_strtotime($end)));

	fwrite($stream, "Timestamp\tBasis\tRealized\tUnrealized\n");

	foreach(iterate_time($pf, $start, $end) as $ts => $d) {
			$basis = $d['totals']['in'] - $d['totals']['out'];
			$real = $d['totals']['realized'];
			if(!$basis) $continue;

			$unreal = -$basis;
			foreach($d['agg'] as $tkr => $a) {
				if(!$a['qty']) continue;
				$unreal += $a['qty'] * get_quote($pf, $tkr, $ts);
			}

			fprintf($stream, "%d\t%f\t%f\t%f\n", $ts, $basis, $real, $unreal);
	}
}

function plot_gains(array $pf, $start, $end, $absolute = true) {
	$dat = tempnam(sys_get_temp_dir(), 'pfm');
	tsv_gains($pf, $datf = fopen($dat, 'wb'), $start, $end, $absolute);
	fclose($datf);

	$sf = popen('gnuplot -p', 'wb');
	fwrite($sf, implode("\n", get_config()['gnuplot_preamble'])."\n");
	fwrite($sf, "set xdata time\n");
	fwrite($sf, "set timefmt '%s'\n");
	fprintf($sf, "set xrange ['%d':'%d']\n", maybe_strtotime($start), maybe_strtotime($end));
	fwrite($sf, "set grid xtics\n");
	fwrite($sf, "set grid mxtics\n");
	fwrite($sf, "set grid ytics\n");
	fwrite($sf, "set grid mytics\n");
	fwrite($sf, "set mytics 2\n");
	fwrite($sf, "set xtics format '%Y-%m-%d' rotate by -90\n");
	fwrite($sf, "show grid\n");
	fwrite($sf, "set key top left inside\n");
	fprintf(
		$sf,
		"plot '%s' using (column('Timestamp')):(%s) with lines title 'Basis' linewidth 2, '' using (column('Timestamp')):(%s) with lines title 'Realized' linewidth 2, '' using (column('Timestamp')):(%s) with lines title 'Unrealized' linewidth 2\n",
		$dat,
		$absolute ? "column('Basis')" : "100.0",
		$absolute ? "column('Realized')" : "100.0 * column('Realized') / column('Basis')",
		$absolute ? "column('Unrealized')" : "100.0 * column('Unrealized') / column('Basis')"
	);

	fwrite($sf, "\n");
	fclose($sf);
	unlink($dat);
}

function tsv_pf(array $pf, $out, $start, $end, &$used = null) {
	$used = [];

	foreach(iterate_time($pf, $start, $end) as $ts => $d) {
		foreach([ $ts, strtotime('+1 day', $ts) - 1 ] as $t) {
			fprintf($out, "%d", $t);
			foreach($pf['lines'] as $tkr => $l) {
				if(!isset($d['agg'][$tkr]) || !$d['agg'][$tkr]['qty']) {
					$value = 0;
				} else {
					$used[$tkr] = true;
					$value = $d['agg'][$tkr]['qty'] * get_quote($pf, $tkr, $ts);
				}

				fprintf($out, "\t%f", $value);
			}
			fprintf($out, "\n");
		}
	}
}

function plot_pf(array $pf, $start, $end, $absolute = true) {
	$dat = tempnam(sys_get_temp_dir(), 'pfm');
	tsv_pf($pf, $datf = fopen($dat, 'wb'), $start, $end, $used);
	fclose($datf);

	$sf = popen('gnuplot -p', 'wb');
	fwrite($sf, "set size ratio .5625\n");
	fwrite($sf, "set xdata time\n");
	fwrite($sf, "set timefmt '%s'\n");
	fprintf($sf, "set xrange ['%s':'%s']\n", maybe_strtotime($start), maybe_strtotime($end));
	fwrite($sf, "set style fill solid 0.5 noborder\n");
	fwrite($sf, "set grid xtics\n");
	fwrite($sf, "set grid ytics\n");
	fwrite($sf, "set grid mytics\n");
	fwrite($sf, "set mytics 2\n");
	fwrite($sf, "set xtics format '%Y-%m-%d' rotate by -90\n");
	fwrite($sf, "show grid\n");
	fwrite($sf, "set key outside\n");

	if(!$absolute) {
		fwrite($sf, "set yrange [0:100]\n");
		$tot = '$'.implode('+$', range(2, count($pf['lines'])+1));
		$tot = '(('.$tot.')/100)';
	}


	fwrite($sf, "plot ");
	$i = 2;
	$base = '0';
	$n = count($used);
	$j = 0;

	foreach($pf['lines'] as $tkr => $l) {
		if(!isset($used[$tkr])) {
			++$i;
			continue;
		}

		if($j) fwrite($sf, ', ');

		fprintf(
			$sf,
			"'%s' using 1:((%s+\$%d)/(%s)):((%s)/(%s)) with filledcurves linecolor '%s' title '%s'",
			$dat,
			$base,
			$i,
			$absolute ? 1 : $tot,
			$base,
			$absolute ? 1 : $tot,
			$color = hsl_to_rgb(($j++) / $n, 1.0, 0.4),
			$tkr
		);

		$base .= '+$'.$i;

		++$i;
	}

	fwrite($sf, "\n");
	fclose($sf);
	unlink($dat);
}

function hsl_to_rgb($h, $s, $l) {
	$h = fmod($h, 1.0) * 6.0;

	$c = (1.0 - abs(2.0 * $l - 1.0)) * $s;
	$x = $c * (1.0 - abs(fmod($h, 2.0) - 1));

	list($r, $g, $b) = [
		[ $c, $x, 0.0 ],
		[ $x, $c, 0.0 ],
		[ 0.0, $c, $x ],
		[ 0.0, $x, $c ],
		[ $x, 0.0, $c ],
		[ $c, 0.0, $x ],
		[ 0.0, 0.0, 0.0 ]
	][floor($h)];

	$m = $l - $c / 2.0;
	return sprintf(
		'#%02X%02X%02X',
		floor(255.0 * ($r + $m)),
		floor(255.0 * ($g + $m)),
		floor(255.0 * ($b + $m))
	);
}
