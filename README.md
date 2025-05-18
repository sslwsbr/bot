# Ssl.WS Bot
Biblioteca em PHP para a emissão e renovação de certificados ssl da plataforma
[ssl.ws](https://ssl.ws)

## Como Instalar?

Realize a instalação utilizando os comandos abaixo:
```
sudo wget -O sslws_bot.phar  https://github.com/sslwsbr/bot/raw/main/sslws_bot.phar
sudo mv sslws_bot.phar /usr/local/bin/sslws_bot
chmod +x /usr/local/bin/sslws_bot
```
## Como emitir um certificado?
Utilize o comando abaixo modificando os parametros:
```
sudo sslws_bot --w=servidor_web --c=codigo_de_download --p=document_root
```
## Configurar cron para emissão e renovação automatica.
Adicione o comando utilizado para emitir o certificado no crontab. O certificado será renovado automaticamente.
```
* */2 * * sudo sslws_bot --w=servidor_web --c=codigo_de_download --p=document_root >/dev/null 2>&1
```
## Como recompilar os arquivos do BOT?
Utilize o comando abaixo para compilar os arquivos do projeto e gerar o sslws_bot.phar.
```
php --define phar.readonly=0 compile.php
```
