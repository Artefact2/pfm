<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function tsv_perf(array $pf, $stream, $start, $end, $absolute = true, array $overlays = []) {	
	$start = strtotime(date('Y-m-d', maybe_strtotime($start)));
	$end = strtotime(date('Y-m-d', maybe_strtotime($end)));

	foreach($overlays as $ol) {
		if(!isset($pf['lines'][$ol])) {
			fatal("Overlay must be a valid ticker: %s\n", $ol);
		}
	}

	if($absolute && $overlays !== []) {
		fatal("Overlays not available in absolute mode\n");
	}
	
	$txs = $pf['tx'];
	reset($txs);
	$tx = current($txs);
	$in = 0;

	$hold = [];
	$obases = [];

	/* XXX: refactor with iterate_tx */
	while($start <= $end) {
		while($tx !== false && $tx['ts'] <= $start) {
			if(!isset($hold[$tx['ticker']])) {
				$hold[$tx['ticker']] = 0;
			}

			$hold[$tx['ticker']] += $tx['buy'];
			$in += ($deltain = $tx['fee'] + $tx['buy']*$tx['price']);
			$tx = next($txs);

			if(isset($firstv)) $firstv += $deltain;
		}

		$nextstart = strtotime('+1 day', $start);

		$value = 0;
		foreach($hold as $ticker => $qty) {
			$value += get_quote($pf, $ticker, $start) * $qty;
		}

		if($absolute) {
			foreach([ $start, $nextstart - 1 ] as $ts) {
				fprintf(
					$stream,
					"%d\t%f\t%f\n",
					$ts,
					$in,
					$value - $in
				);
			}
		} else {
			$ovals = [];
			
			if(!isset($firstv)) {
				$firstv = $value > 0 ? $value : $in;
				$v = 0.0;

				foreach($overlays as $t) {
					$obases[$t] = get_quote($pf, $t, $start);
					$ovals[$t] = 100.0;
				}
			} else if($firstv > 0) {
				$v = 100.0 * ($value - $firstv) / $firstv;
				foreach($overlays as $t) $ovals[$t] = 100.0 * get_quote($pf, $t, $start) / $obases[$t];
			} else {
				$v = 0.0;

				foreach($overlays as $t) $ovals[$t] = 100.0;
			}

			foreach([ $start, $nextstart - 1 ] as $ts) {
				fprintf(
					$stream,
					"%d\t%f\t%f",
					$ts,
					100,
					$v
				);

				foreach($overlays as $t) fprintf($stream, "\t%f", $ovals[$t]);

				fwrite($stream, "\n");
			}
		}
	    
		$start = $nextstart;
	}
}

function plot_perf(array $pf, $start, $end, $absolute = true, array $overlays = []) {
	$dat = tempnam(sys_get_temp_dir(), 'pfm');
	tsv_perf($pf, $datf = fopen($dat, 'wb'), $start, $end, $absolute, $overlays);
	fclose($datf);
	
	$sf = popen('gnuplot -p', 'wb');
	fwrite($sf, "set xdata time\n");
	fwrite($sf, "set timefmt '%s'\n");
	fprintf($sf, "set xrange ['%d':'%d']\n", maybe_strtotime($start), maybe_strtotime($end));
	fwrite($sf, "set style fill solid 0.5 noborder\n");
	fwrite($sf, "set grid xtics\n");
	fwrite($sf, "set grid ytics\n");
	fwrite($sf, "set grid mytics\n");
	fwrite($sf, "set mytics 2\n");
	fwrite($sf, "set xtics format '%Y-%m-%d' rotate by -90\n");
	fwrite($sf, "show grid\n");
	fwrite($sf, "set key outside\n");
	fprintf(
		$sf,
		"plot '%s' using 1:(\$2+\$3):2 with filledcurves above linecolor '#008000' title 'Gains', '%s' using 1:(\$2+\$3):2 with filledcurves below linecolor '#800000' title 'Losses'",
		$dat,
		$dat
	);

	$i = 4;
	foreach($overlays as $o) {
		fprintf(
			$sf,
			", '%s' using 1:$i with lines linecolor '#000000' title '%s'",
			$dat,
			$o
		);
		++$i;
		/* TODO: colorize overlay lines */
	}

	fwrite($sf, "\n");
	fclose($sf);
	unlink($dat);
}

function tsv_pf(array $pf, $out, $start, $end, &$used = null) {
	$used = [];
	
	foreach(iterate_tx($pf, $start, $end) as $ts => $d) {
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
