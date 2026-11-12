"""Media storage abstraction. Services pass *keys*, never paths or URLs, between each other.

`LocalStorage` maps keys onto a directory shared between services (a Docker volume in
development). `S3Storage` talks to any S3-compatible bucket. Keys are validated so a caller can
never reach outside the storage root.
"""

from __future__ import annotations

import asyncio
import re
import shutil
import tempfile
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any, Protocol

from studio_common.config import ServiceSettings
from studio_common.errors import ServiceError

_KEY = re.compile(r"[A-Za-z0-9][A-Za-z0-9._\-/]{0,254}")


def validate_key(key: str) -> str:
    """Return `key` if it is a safe relative storage key, else raise a 422 error."""
    if (
        not _KEY.fullmatch(key)
        or ".." in key.split("/")
        or "//" in key
        or key.endswith("/")
        or any(part.startswith(".") for part in key.split("/"))
    ):
        raise ServiceError(422, "invalid_storage_key", "The storage key is not valid.")
    return key


class Storage(Protocol):
    async def exists(self, key: str) -> bool: ...

    def local_copy(self, key: str) -> Any:
        """Async context manager yielding a local read-only `Path` for `key`."""
        ...

    async def store_file(self, source: Path, key: str) -> int:
        """Copy a local file into storage under `key`; return its size in bytes."""
        ...

    async def check(self) -> None: ...


class LocalStorage:
    def __init__(self, root: str | Path) -> None:
        self.root = Path(root)

    def _resolve(self, key: str) -> Path:
        path = (self.root / validate_key(key)).resolve()
        if not path.is_relative_to(self.root.resolve()):
            raise ServiceError(422, "invalid_storage_key", "The storage key is not valid.")
        return path

    async def exists(self, key: str) -> bool:
        return self._resolve(key).is_file()

    @asynccontextmanager
    async def local_copy(self, key: str) -> AsyncIterator[Path]:
        path = self._resolve(key)
        if not path.is_file():
            raise ServiceError(404, "media_not_found", "A referenced media file does not exist.")
        yield path

    async def store_file(self, source: Path, key: str) -> int:
        destination = self._resolve(key)

        def copy() -> int:
            destination.parent.mkdir(parents=True, exist_ok=True)
            temporary = destination.with_name(destination.name + ".part")
            shutil.copyfile(source, temporary)
            temporary.replace(destination)
            return destination.stat().st_size

        return await asyncio.to_thread(copy)

    async def check(self) -> None:
        self.root.mkdir(parents=True, exist_ok=True)
        probe = self.root / ".healthcheck"
        probe.write_text("ok")
        probe.unlink()


class S3Storage:
    """S3-compatible storage. Needs the optional `boto3` package (extra: `studio-common[s3]`)."""

    def __init__(self, settings: ServiceSettings) -> None:
        import boto3  # imported lazily so local-only installs do not need it

        if not (settings.s3_access_key and settings.s3_secret_key and settings.s3_bucket):
            raise RuntimeError("S3 storage is not configured")
        self._bucket = settings.s3_bucket
        self._client = boto3.client(
            "s3",
            endpoint_url=settings.s3_endpoint,
            region_name=settings.s3_region,
            aws_access_key_id=settings.s3_access_key.get_secret_value(),
            aws_secret_access_key=settings.s3_secret_key.get_secret_value(),
        )

    async def exists(self, key: str) -> bool:
        validate_key(key)

        def head() -> bool:
            try:
                self._client.head_object(Bucket=self._bucket, Key=key)
            except Exception:
                return False
            return True

        return await asyncio.to_thread(head)

    @asynccontextmanager
    async def local_copy(self, key: str) -> AsyncIterator[Path]:
        validate_key(key)
        if not await self.exists(key):
            raise ServiceError(404, "media_not_found", "A referenced media file does not exist.")
        with tempfile.TemporaryDirectory(prefix="media-") as directory:
            path = Path(directory) / Path(key).name
            await asyncio.to_thread(self._client.download_file, self._bucket, key, str(path))
            yield path

    async def store_file(self, source: Path, key: str) -> int:
        validate_key(key)
        await asyncio.to_thread(self._client.upload_file, str(source), self._bucket, key)
        return source.stat().st_size

    async def check(self) -> None:
        await asyncio.to_thread(self._client.head_bucket, Bucket=self._bucket)


def build_storage(settings: ServiceSettings) -> Storage:
    if settings.storage_backend == "s3":
        return S3Storage(settings)
    return LocalStorage(settings.storage_local_root)


class StorageCheck:
    name = "storage"

    def __init__(self, storage: Storage) -> None:
        self._storage = storage

    async def check(self) -> None:
        await self._storage.check()
