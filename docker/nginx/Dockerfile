FROM nginx:alpine

# Copy custom configuration
COPY default.conf /etc/nginx/conf.d/default.conf

# Create web root directory
RUN mkdir -p /var/www/html/public

# Set proper permissions
RUN chown -R nginx:nginx /var/www/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
