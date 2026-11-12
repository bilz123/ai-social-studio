from __future__ import annotations

import asyncio
from typing import Any

from studio_common.errors import ServiceError
from studio_common.jobs import InMemoryJobStore, JobContext, JobManager, JobStatus


async def _wait(manager: JobManager, job_id: str) -> Any:
    for _ in range(200):
        job = await manager.store.get(job_id)
        if job and job.status in (JobStatus.SUCCEEDED, JobStatus.FAILED, JobStatus.CANCELED):
            return job
        await asyncio.sleep(0.01)
    raise AssertionError("job did not finish")


async def test_success_records_result_and_progress() -> None:
    manager = JobManager(InMemoryJobStore(), kind="t")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        await ctx.update("working", 0.5, provider_job_id="abc")
        return {"ok": True}

    job, created = await manager.submit(runner)
    done = await _wait(manager, job.id)
    assert created
    assert done.status == JobStatus.SUCCEEDED
    assert done.result == {"ok": True}
    assert done.progress == 1.0
    assert done.details["provider_job_id"] == "abc"


async def test_service_error_becomes_a_failed_job_with_a_code() -> None:
    manager = JobManager(InMemoryJobStore(), kind="t")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        raise ServiceError(502, "provider_unavailable", "Provider is down.", retryable=True)

    job, _ = await manager.submit(runner)
    done = await _wait(manager, job.id)
    assert done.status == JobStatus.FAILED
    assert done.error.code == "provider_unavailable"
    assert done.error.retryable is True


async def test_unexpected_errors_do_not_leak_details() -> None:
    manager = JobManager(InMemoryJobStore(), kind="t")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        raise RuntimeError("secret-token-123")

    job, _ = await manager.submit(runner)
    done = await _wait(manager, job.id)
    assert done.error.code == "internal_error"
    assert "secret" not in done.error.message


async def test_timeout_is_reported_as_retryable() -> None:
    manager = JobManager(InMemoryJobStore(), kind="t")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        raise TimeoutError

    job, _ = await manager.submit(runner)
    done = await _wait(manager, job.id)
    assert done.error.code == "timeout"
    assert done.error.retryable is True


async def test_same_idempotency_key_returns_same_job() -> None:
    manager = JobManager(InMemoryJobStore(), kind="t")
    gate = asyncio.Event()

    async def runner(ctx: JobContext) -> dict[str, Any]:
        await gate.wait()
        return {}

    first, created_first = await manager.submit(runner, idempotency_key="k1")
    second, created_second = await manager.submit(runner, idempotency_key="k1")
    assert created_first and not created_second
    assert first.id == second.id
    gate.set()
    await _wait(manager, first.id)


async def test_cancel_stops_a_running_job() -> None:
    manager = JobManager(InMemoryJobStore(), kind="t")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        await asyncio.sleep(30)
        return {}

    job, _ = await manager.submit(runner)
    await asyncio.sleep(0.05)
    canceled = await manager.cancel(job.id)
    assert canceled is not None
    assert canceled.status == JobStatus.CANCELED


async def test_recover_fails_jobs_a_previous_process_left_open() -> None:
    store = InMemoryJobStore()
    first = JobManager(store, kind="t")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        await asyncio.sleep(30)
        return {}

    job, _ = await first.submit(runner)
    await asyncio.sleep(0.02)
    # A new process (new manager, no tasks) starts against the same store.
    second = JobManager(store, kind="t")
    assert await second.recover() == 1
    stored = await store.get(job.id)
    assert stored is not None
    assert stored.error is not None and stored.error.code == "interrupted"
    await first.shutdown()
