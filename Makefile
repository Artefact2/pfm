PREFIX?=/usr

pfm.phar: $(shell find src -name "*.php")
	git describe --always > ext/version
	date -uIns > ext/build-datetime
	./gen-phar $@ $^ ext/version ext/build-datetime

clean:
	rm -f pfm.phar

install: pfm.phar
	install $< $(PREFIX)/bin/pfm
	install -D COPYING $(PREFIX)/share/licenses/pfm/COPYING
	install -D ext/firejail/pfm.profile /etc/firejail/pfm.profile
	install -D pfm.ini /etc/xdg/pfm/pfm.ini

uninstall:
	rm -f $(PREFIX)/bin/pfm
	rm -f $(PREFIX)/share/licenses/pfm/COPYING
	rmdir $(PREFIX)/share/licenses/pfm
	rm -f /etc/firejail/pfm.profile /etc/xdg/pfm/pfm.ini
	rmdir /etc/xdg/pfm

.PHONY: clean install uninstall
