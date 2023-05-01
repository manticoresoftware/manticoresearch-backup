FROM manticoresearch/manticore:dev
ARG APP_NAME
RUN EXTRA=1 docker-entrypoint.sh && \
  echo "common {\n\
    plugin_dir = /usr/local/lib/manticore\n\
  }\n\
  searchd {\n\
    listen = 0.0.0.0:9312\n\
    listen = 0.0.0.0:9306:mysql\n\
    listen = 0.0.0.0:9308:http\n\
    log = /var/log/manticore/searchd.log\n\
    query_log = /var/log/manticore/query.log\n\
    pid_file = /var/run/manticore/searchd.pid\n\
    data_dir = /var/lib/manticore\n\
    query_log_format = sphinxql\n\
  }" > /etc/manticoresearch/manticore.conf

COPY build/share/modules/$APP_NAME /usr/share/manticore/modules/$APP_NAME
COPY build/$APP_NAME /usr/bin/$APP_NAME
