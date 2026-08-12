import assert from "node:assert/strict";
import { mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { createRunMounts } from "../src/gondolin-adapter.js";

test("run VFS exposes only an immutable capsule and narrow writable mounts", async (t) => {
  const root = await mkdtemp(path.join(os.tmpdir(), "wtf-sandbox-vfs-"));
  t.after(() => rm(root, { recursive: true, force: true }));
  const capsuleRoot = path.join(root, "capsule");
  const outputRoot = path.join(root, "output");
  await mkdir(capsuleRoot);
  await mkdir(outputRoot);
  await writeFile(path.join(capsuleRoot, "run.mjs"), "console.log('{}');\n");

  const mounts = createRunMounts(capsuleRoot, outputRoot);
  assert.deepEqual(Object.keys(mounts).sort(), ["/capsule", "/output", "/scratch"]);
  assert.equal(Object.hasOwn(mounts, "/"), false);
  assert.equal(mounts["/capsule"]?.readonly, true);
  assert.equal(mounts["/scratch"]?.readonly, false);
  assert.equal(mounts["/output"]?.readonly, false);

  await assert.rejects(
    () => mounts["/capsule"]!.open("/run.mjs", "w"),
    (error: unknown) => {
      const denied = error as NodeJS.ErrnoException;
      return denied.code === "EROFS" || denied.code === "ERRNO_30";
    },
  );
  assert.equal(
    await readFile(path.join(capsuleRoot, "run.mjs"), "utf8"),
    "console.log('{}');\n",
  );

  const scratch = await mounts["/scratch"]!.open("/temp.json", "w+");
  await scratch.writeFile("temporary");
  await scratch.close();
  await assert.rejects(() => readFile(path.join(root, "scratch", "temp.json")));

  const output = await mounts["/output"]!.open("/result.json", "w+");
  await output.writeFile('{"ok":true}');
  await output.close();
  assert.equal(
    await readFile(path.join(outputRoot, "result.json"), "utf8"),
    '{"ok":true}',
  );
});
