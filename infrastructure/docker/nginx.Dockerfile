FROM nginx:1.29-alpine
COPY infrastructure/docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY apps/api/public /var/www/html/public

