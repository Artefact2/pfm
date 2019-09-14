<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function tsv_gains(array &$pf, $stream, $start, $end) {
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

function plot_gains(array &$pf, $start, $end, $absolute = true) {
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
		$absolute ? "column('Unrealized') + column('Basis')" : "100.0 + 100.0 * column('Unrealized') / column('Basis')"
	);

	fwrite($sf, "\n");
	fclose($sf);
	unlink($dat);
}

function tsv_lines(array &$pf, $out, $start, $end, &$used = null) {
	$used = [];

	fwrite($out, "Timestamp");

	foreach($pf['lines'] as $tkr => $l) {
		fprintf($out, "\ti%s\to%s\tn%s\tp%s", $tkr, $tkr, $tkr, $tkr);
	}
	fwrite($out, "\t__bench__\n");

	foreach(iterate_time($pf, $start, $end) as $ts => $d) {
		fprintf($out, "%d", $ts);
		foreach($pf['lines'] as $tkr => $l) {
			if(!isset($d['agg'][$tkr]) || !$d['agg'][$tkr]['qty']) {
				$num = 0;
				$quote = 0;
			} else {
				$used[$tkr] = true;
				$num = $d['agg'][$tkr]['qty'];
				$quote = get_quote($pf, $tkr, $ts);
			}

			fprintf($out, "\t%f\t%f\t%f\t%f", $d['agg'][$tkr]['in'] ?? 0, $d['agg'][$tkr]['out'] ?? 0, $num, $quote);
		}

		$bench = 0.0;
		foreach($d['bagg'] as $tkr => $l) {
			$bench += $l['qty'] * get_quote($pf, $tkr, $ts);
		}
		fprintf($out, "\t%f\n", $bench);
	}
}

function plot_lines(array &$pf, $start, $end, $lines = 'all', $absolute = true, $total = true, $benchmark = true) {
	$dat = tempnam(sys_get_temp_dir(), 'pfm');
	tsv_lines($pf, $datf = fopen($dat, 'wb'), $start, $end, $used);
	fclose($datf);

	$sf = popen('gnuplot -p', 'wb');
	fwrite($sf, implode("\n", get_config()['gnuplot_preamble'])."\n");
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
	fwrite($sf, "set key inside top left\n");

	if(!$absolute && !$benchmark) {
		fwrite($sf, "set yrange [0:*<100]\n");
	}

	$tot = [];
	$plots = [];

	foreach($pf['lines'] as $tkr => $l) {
		if(!isset($used[$tkr])) {
			continue;
		}

		$tot[$tkr] = sprintf('column("n%s")*column("p%s")', $tkr, $tkr);
	}
	$totstr = implode('+', $tot);


	if($lines === 'none') {
		$lines = [];
	} else if($lines === 'all') {
		$lines = array_flip(array_keys($pf['lines']));
	} else {
		$lines = array_flip(explode(',', $lines));
	}

	foreach($pf['lines'] as $tkr => $l) {
		if(!isset($used[$tkr]) || !isset($lines[$tkr])) {
			continue;
		}

		$plots[] = sprintf(
			"'%s' using (column('Timestamp')):(%s) with lines title '%s' linewidth 2",
			$dat,
			$absolute ? $tot[$tkr] : sprintf('100.0*(%s)/(%s)', $tot[$tkr], $totstr),
			$tkr
		);
	}

	if($total) {
		$plots[] = sprintf("'%s' using (column('Timestamp')):(%s) with lines title 'Total' linewidth 2", $dat, $absolute ? $totstr : '100.0');
	}
	if($benchmark) {
		$plots[] = sprintf("'%s' using (column('Timestamp')):(column('__bench__')/(%s)) with lines title 'Benchmark' linewidth 2", $dat, $absolute ? '1.0' : '0.01*('.$totstr.')');
	}

	fprintf($sf, "plot %s\n", implode(', ', $plots));
	fclose($sf);
	unlink($dat);
}
