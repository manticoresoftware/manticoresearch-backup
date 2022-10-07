FROM php:8.1.11-cli-buster

ARG TARGET_ARCH=amd64
ENV VERSION=0.2.1
ENV SUFFIX=221005-c39fc10
ENV DEB_PKG=manticore-executor_${VERSION}-${SUFFIX}_${TARGET_ARCH}.deb
RUN apt -y update && apt -y upgrade && \
  apt -y install figlet git zip unzip wget curl gpg && \
  \
  wget https://repo.manticoresearch.com/manticore-dev-repo.noarch.deb && \
  dpkg -i manticore-dev-repo.noarch.deb && \
  apt -y update && apt -y install manticore && \
  apt-get -y autoremove && apt-get -y clean && \
  \
  wget https://github.com/manticoresoftware/executor/releases/download/v${VERSION}/${DEB_PKG} && \
  dpkg -i ${DEB_PKG} && \
  rm -f ${DEB_PKG}

# alter bash prompt
ENV PS1A="\u@manticore-backup.test:\w> "
RUN echo 'PS1=$PS1A' >> ~/.bashrc && \
  echo 'figlet -w 120 manticore-backup script testing' >> ~/.bashrc

# install composer - see https://medium.com/@c.harrison/speedy-composer-installs-in-docker-builds-41eea6d0172b
RUN curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer

RUN mkdir /var/run/manticore/ && \
  mkdir -p /usr/share/manticore/morph/ && \
  echo -e 'a\nb\nc\nd\n' > /usr/share/manticore/morph/test

RUN echo "common { \n\
    plugin_dir = /usr/local/lib/manticore\n\
    lemmatizer_base = /usr/share/manticore/morph/\n\
}\n\
searchd {\n\
    listen = 0.0.0:9312\n\
    listen = 0.0.0:9306:mysql\n\
    listen = 0.0.0:9308:http\n\
    log = /var/log/manticore/searchd.log\n\
    query_log = /var/log/manticore/query.log\n\
    pid_file = /var/run/manticore/searchd.pid\n\
    data_dir = /var/lib/manticore\n\
    query_log_format = sphinxql\n\
}\n" > "/etc/manticoresearch/manticore.conf"

# Prevent the container from exiting
ENTRYPOINT ["tail"]
CMD ["-f", "/dev/null"]
