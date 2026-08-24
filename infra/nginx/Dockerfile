FROM nginx:1.29.1-alpine
COPY nginx/nginx.conf /etc/nginx/nginx.conf
COPY nginx/spcambo-fastcgi.conf /etc/nginx/spcambo-fastcgi.conf
COPY backend-public /var/www/html/public
