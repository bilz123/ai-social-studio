from __future__ import annotations

from pathlib import Path

import pytest

from studio_common.errors import ServiceError
from studio_common.storage import LocalStorage, validate_key


@pytest.mark.parametrize(
    "key", ["../x", "/abs", "a//b", "a/../b", ".hidden", "a/.hidden", "a/", "", "a b", "x" * 300]
)
def test_unsafe_keys_are_rejected(key: str) -> None:
    with pytest.raises(ServiceError) as caught:
        validate_key(key)
    assert caught.value.code == "invalid_storage_key"


@pytest.mark.parametrize("key", ["a.jpg", "users/01/projects/02/original/03.jpg", "a-b_c.1/d"])
def test_safe_keys_pass(key: str) -> None:
    assert validate_key(key) == key


async def test_local_storage_round_trip(tmp_path: Path) -> None:
    storage = LocalStorage(tmp_path / "root")
    source = tmp_path / "src.bin"
    source.write_bytes(b"hello")
    assert not await storage.exists("out/a.bin")
    assert await storage.store_file(source, "out/a.bin") == 5
    assert await storage.exists("out/a.bin")
    async with storage.local_copy("out/a.bin") as path:
        assert path.read_bytes() == b"hello"
    assert not list((tmp_path / "root" / "out").glob("*.part"))


async def test_missing_media_is_a_404(tmp_path: Path) -> None:
    storage = LocalStorage(tmp_path)
    with pytest.raises(ServiceError) as caught:
        async with storage.local_copy("nope.jpg"):
            pass
    assert caught.value.status_code == 404
