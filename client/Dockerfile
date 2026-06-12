FROM node:20-alpine

WORKDIR /app

COPY docker-start.sh /app/docker-start.sh
COPY docker-start.dev.sh /app/docker-start.dev.sh
COPY docker-start.prod.sh /app/docker-start.prod.sh
RUN chmod +x /app/docker-start.sh /app/docker-start.dev.sh /app/docker-start.prod.sh

EXPOSE 5173

CMD ["sh", "/app/docker-start.dev.sh"]
