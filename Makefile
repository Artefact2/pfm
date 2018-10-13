PREFIX?=/usr/local

pfm.phar: $(shell find src -name "*.php")
	./gen-phar $@ $^

clean:
	rm -f pfm.phar

install: pfm.phar
	install $< $(PREFIX)/bin/pfm
	install -D COPYING $(PREFIX)/share/licenses/pfm/COPYING

uninstall:
	rm -f $(PREFIX)/bin/pfm
	rm -f $(PREFIX)/share/licenses/pfm/COPYING
	rmdir $(PREFIX)/share/licenses/pfm

.PHONY: clean install uninstall
