FROM php:8.1.10-cli-buster

ARG TARGET_ARCH=amd64
RUN apt -y update && apt -y upgrade && \
  apt -y install figlet git zip unzip wget curl gpg && \
  \
  wget https://repo.manticoresearch.com/manticore-dev-repo.noarch.deb && \
  dpkg -i manticore-dev-repo.noarch.deb && \
  apt -y update && apt -y install manticore && \
  apt-get -y autoremove && apt-get -y clean && \
  \
  wget https://github.com/manticoresoftware/executor/releases/download/v0.1.0/manticore-executor_0.1.0_${TARGET_ARCH}.deb && \
  dpkg -i manticore-executor_0.1.0_${TARGET_ARCH}.deb && \
  rm -f manticore-executor_0.1.0_${TARGET_ARCH}.deb

# alter bash prompt
ENV PS1A="\u@manticore-backup.test:\w> "
RUN echo 'PS1=$PS1A' >> ~/.bashrc && \
  echo 'figlet -w 120 manticore-backup script testing' >> ~/.bashrc

# install composer - see https://medium.com/@c.harrison/speedy-composer-installs-in-docker-builds-41eea6d0172b
RUN curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer

RUN mkdir /var/run/manticore/

# Prevent the container from exiting
ENTRYPOINT ["searchd"]
CMD ["--nodetach"]
