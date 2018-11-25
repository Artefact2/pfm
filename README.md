# pfm

A simple command-line stock portfolio manager.

Released under the WTFPLv2 license.

# Demo

[![asciicast](https://asciinema.org/a/XYRN7O4hZe9nSgYFipeQ56tLe.svg)](https://asciinema.org/a/XYRN7O4hZe9nSgYFipeQ56tLe)

# Dependencies

* PHP ≥ 7 (cli only)
* Gnuplot (optional; for plot commands)
* Firejail (optional)

# Install

~~~
make
sudo make install PREFIX=/usr
sudo ln -s /usr/bin/firejail /usr/local/bin/pfm
~~~

# Usage

~~~
Available commands:
pfm [version]
pfm help|h
pfm status|s [at:<date>]
pfm perf|p [at:<date>] [columns:default|days|weeks|months|years]
pfm add-line name:<name> ticker:<ticker> currency:<currency> isin:<ISIN>
pfm rm-line ticker:<ticker>
pfm edit-line ticker:<ticker> [<field1>:<newval1>] [<field2>:<newval2>]…
pfm ls-lines
pfm add-tx ticker:<ticker> [sell:<quantity>|all] [buy:<quantity>] [price:<unit-price>] [fee:<fee>] [total:<total>] [date:<date>]
pfm rm-tx <txid>...
pfm ls-tx [ticker:<ticker>] [before:<date>] [after:<date>]
pfm get-quote ticker:<ticker> [at:<date>]
pfm plot-perf [start:<date>] [end:<date>] [absolute:0|1] [raw:0|1]
pfm plot-pf [start:<date>] [end:<date>] [absolute:0|1] [raw:0|1]
pfm quotes-to-gnucash <file.gnucash>
~~~

# About transactions

Transaction total is calculated using the transaction equation:

~~~
total = (-quantity) * price - fee
~~~

Where quantity is positive for buying, negative for selling. When
buying, set user-supplied `total` as negative.

When using `add-tx`, exactly three values from total, quantity, price
and fee must be supplied, for example:

~~~
# Calculated tx fee of 2
pfm add-tx ticker:FOO buy:10 price:5 total:52

# Calculated price of 5
pfm add-tx ticker:FOO buy:10 fee:2 total:52
~~~

Dividends and management fees can be input as follows:

~~~
# Gain a €12.34 dividend from FOO stocks, as cash
pfm add-tx ticker:FOO sell:0 price:0 fee:-12.34

# Gain a €45.67 dividend from FOO stocks, automatically reinvested
pfm add-tx ticker:FOO buy:0.1234 fee:-45.67 total:0

# Management fee of €1.23
pfm add-tx ticker:FOO sell:0.4567 fee:1.23 total:0
~~~
