# syntax=docker/dockerfile:1
# Python AI service image. Only the `dev` target exists for now; the production target (no dev
# tools, no reload, FFmpeg for video work) is added in Phase 2 / Phase 6.

FROM python:3.12-slim AS base
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1
WORKDIR /srv
RUN useradd --create-home --uid 10001 app

FROM base AS dev
COPY services/ai/pyproject.toml ./
# Install the dependencies declared in pyproject.toml (runtime + dev) without installing the app
# itself: the source is on the path via /srv (copied below, bind-mounted in development).
RUN python -c "import tomllib; p = tomllib.load(open('pyproject.toml', 'rb'))['project']; print('\n'.join(p['dependencies'] + p['optional-dependencies']['dev']))" > /tmp/requirements.txt \
    && pip install -r /tmp/requirements.txt \
    && rm /tmp/requirements.txt
COPY services/ai/app ./app
COPY services/ai/tests ./tests
USER app
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--no-access-log"]
