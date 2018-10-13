PREFIX?=/usr

pfm.phar: $(shell find src -name "*.php")
	./gen-phar $@ $^

clean:
	rm -f pfm.phar

install: pfm.phar
	install $< $(PREFIX)/bin/pfm
	install -D COPYING $(PREFIX)/share/licenses/pfm/COPYING
	install -D ext/firejail/pfm.profile /etc/firejail/pfm.profile

uninstall:
	rm -f $(PREFIX)/bin/pfm
	rm -f $(PREFIX)/share/licenses/pfm/COPYING
	rmdir $(PREFIX)/share/licenses/pfm
	rm -f /etc/firejail/pfm.profile

.PHONY: clean install uninstall
