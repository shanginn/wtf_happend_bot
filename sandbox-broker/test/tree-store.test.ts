import assert from "node:assert/strict";
import { chmod, mkdir, mkdtemp, readFile, rm, stat, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { hashDirectory, TreeStore } from "../src/tree-store.js";

test("materializes a digest-matched capsule as a read-only copy", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "sandbox-tree-store-"));
  const destination = path.join(root, "destination");
  try {
    const staging = path.join(root, "staging");
    await mkdir(staging);
    const executable = path.join(staging, "run");
    await writeFile(executable, "#!/bin/sh\necho ok\n", { mode: 0o755 });
    const digest = await hashDirectory(staging);
    const capsule = path.join(root, "capsules", "sha256", digest);
    await mkdir(path.dirname(capsule), { recursive: true });
    await import("node:fs/promises").then(({ rename }) => rename(staging, capsule));

    const store = new TreeStore(
      path.join(root, "capsules"),
      path.join(root, "state"),
    );
    const copiedDigest = await store.materializeCapsule(
      `sha256:${digest}`,
      destination,
    );

    assert.equal(copiedDigest, digest);
    assert.equal(await readFile(path.join(destination, "run"), "utf8"), "#!/bin/sh\necho ok\n");
    const mode = (await stat(path.join(destination, "run"))).mode & 0o777;
    assert.equal(mode, 0o500);
  } finally {
    await chmod(destination, 0o700).catch(() => undefined);
    await chmod(path.join(destination, "run"), 0o600).catch(() => undefined);
    await chmod(root, 0o700).catch(() => undefined);
    await rm(root, { recursive: true, force: true });
  }
});

test("stages JavaScript into an immutable content-addressed tree", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "sandbox-tree-stage-"));
  let objectRoot = "";
  try {
    const capsuleRoot = path.join(root, "capsules");
    const store = new TreeStore(capsuleRoot, path.join(root, "state"));
    const first = await store.stageJavascriptCapsule(
      "console.log('ok');\n",
      "tools/run.mjs",
    );
    objectRoot = path.join(
      capsuleRoot,
      "sha256",
      first.digest.slice("sha256:".length),
    );
    const replay = await store.stageJavascriptCapsule(
      "console.log('ok');\n",
      "tools/run.mjs",
    );

    assert.deepEqual(replay, first);
    assert.deepEqual(first.entrypoint, ["/data/capsule/tools/run.mjs"]);
    assert.equal(
      await readFile(path.join(objectRoot, "tools", "run.mjs"), "utf8"),
      "#!/usr/bin/env node\nconsole.log('ok');\n",
    );
    assert.equal((await stat(objectRoot)).mode & 0o777, 0o500);
  } finally {
    if (objectRoot) {
      await chmod(objectRoot, 0o700).catch(() => undefined);
      await chmod(path.join(objectRoot, "tools"), 0o700).catch(() => undefined);
      await chmod(path.join(objectRoot, "tools", "run.mjs"), 0o600).catch(
        () => undefined,
      );
    }
    await rm(root, { recursive: true, force: true });
  }
});

test("rejects a corrupted existing content-addressed output", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "sandbox-tree-output-"));
  try {
    const output = path.join(root, "output");
    const state = path.join(root, "state");
    await mkdir(output);
    await writeFile(path.join(output, "answer.json"), '{"ok":true}\n');
    const store = new TreeStore(path.join(root, "capsules"), state);
    const [artifact] = await store.persistOutputs(output, 1, 1024);
    assert.ok(artifact);

    const object = path.join(state, "artifacts", "sha256", artifact.sha256);
    await chmod(object, 0o600);
    await writeFile(object, "corrupted\n");

    await assert.rejects(
      store.persistOutputs(output, 1, 1024),
      /existing artifact object does not match its content digest/,
    );
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
