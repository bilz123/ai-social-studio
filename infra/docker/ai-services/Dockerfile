# syntax=docker/dockerfile:1
# One image recipe for all five Phase 6 AI microservices (build context: repository root).
#   SERVICE  directory under services/        e.g. video-renderer
#   PACKAGE  Python package to run            e.g. video_renderer
#   FFMPEG   "true" installs FFmpeg + a font  (renderer, optimizer, generator)
# Each service ships as its own image; they share only the `studio_common` library.

FROM python:3.12-slim AS base
ARG FFMPEG=false
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1
RUN if [ "$FFMPEG" = "true" ]; then \
      apt-get update \
      && apt-get install -y --no-install-recommends ffmpeg fonts-dejavu-core \
      && rm -rf /var/lib/apt/lists/*; \
    fi
RUN useradd --create-home --uid 10001 app \
    && mkdir -p /data/media \
    && chown app:app /data/media

FROM base AS runtime
ARG SERVICE
ARG PACKAGE
ENV AI_PACKAGE=${PACKAGE}
WORKDIR /opt
COPY services/common /opt/common
RUN pip install /opt/common
COPY services/${SERVICE} /opt/service
RUN pip install /opt/service && rm -rf /opt/common /opt/service
USER app
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=5s --start-period=15s --retries=5 \
  CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/healthz', timeout=3).status == 200 else 1)"
CMD ["sh", "-c", "exec uvicorn --factory ${AI_PACKAGE}.main:create_app --host 0.0.0.0 --port 8000 --no-access-log"]
