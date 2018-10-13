# Firejail profile for pfm

quiet

include /etc/firejail/pfm.local
include /etc/firejail/globals.local

noblacklist ~/.cache/pfm
noblacklist ~/.config/pfm
noblacklist ~/.local/share/pfm

mkdir ~/.cache/pfm
mkdir ~/.config/pfm
mkdir ~/.local/share/pfm

whitelist ~/.cache/pfm
whitelist ~/.config/pfm
whitelist ~/.local/share/pfm

whitelist ~/.config/fontconfig
whitelist ~/.config/gnuplot
whitelist ~/.fonts
whitelist ~/.fonts.conf
whitelist ~/.fonts.conf.d
whitelist ~/.gnuplot

read-only ~
read-write ~/.cache/pfm
read-write ~/.local/share/pfm

caps.drop all
machine-id
#memory-deny-write-execute
nodvd
nogroups
nonewprivs
noroot
nosound
notv
novideo
protocol unix,inet,inet6
seccomp
shell none

private-bin pfm,env,php,sh,tput,gnuplot*
private-dev
# XXX: xdg/pfm
private-etc xdg,php,ssl,ca-certificates,fonts
# XXX: qt.qpa.plugin: Could not find the Qt platform plugin "xcb" in ""
#private-lib php/modules/*.so
private-opt empty
private-srv empty
private-tmp
