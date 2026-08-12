import { createHash, randomUUID } from "node:crypto";
import {
  chmod,
  copyFile,
  lstat,
  mkdir,
  open,
  readdir,
  realpath,
  rename,
  rm,
  stat,
  writeFile,
} from "node:fs/promises";
import path from "node:path";

import { sha256HexFromReference } from "./contracts.js";

export type StoredArtifact = {
  path: string;
  ref: string;
  sha256: string;
  sizeBytes: number;
};

export class ArtifactError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "ArtifactError";
  }
}

export class TreeStore {
  constructor(
    private readonly capsuleRoot: string,
    private readonly stateRoot: string,
  ) {}

  async materializeCapsule(reference: string, destination: string): Promise<string> {
    const expected = sha256HexFromReference(reference);
    const root = await realpath(this.capsuleRoot);
    const candidate = path.join(root, "sha256", expected);
    const source = await realpath(candidate).catch(() => {
      throw new ArtifactError(`capsule ${reference} does not exist`);
    });
    assertWithin(root, source, "capsule");

    const sourceDigest = await hashDirectory(source);
    if (sourceDigest !== expected) {
      throw new ArtifactError("capsule content does not match its immutable digest");
    }

    await copyDirectory(source, destination);
    const copiedDigest = await hashDirectory(destination);
    if (copiedDigest !== expected) {
      throw new ArtifactError("capsule changed while it was being materialized");
    }
    await makeTreeReadonly(destination);
    return copiedDigest;
  }

  async stageJavascriptCapsule(
    source: string,
    entrypoint: string,
  ): Promise<{ digest: string; entrypoint: string[] }> {
    const stagingRoot = path.join(this.capsuleRoot, ".staging");
    const objectRoot = path.join(this.capsuleRoot, "sha256");
    await mkdir(stagingRoot, { recursive: true, mode: 0o700 });
    await mkdir(objectRoot, { recursive: true, mode: 0o700 });

    const temporary = await import("node:fs/promises").then(({ mkdtemp }) =>
      mkdtemp(path.join(stagingRoot, "capsule-")),
    );
    try {
      const targetFile = path.join(temporary, ...entrypoint.split("/"));
      assertWithin(temporary, targetFile, "entrypoint");
      await mkdir(path.dirname(targetFile), { recursive: true, mode: 0o700 });
      const executableSource = `#!/usr/bin/env node\n${source}`;
      await writeFile(targetFile, executableSource, {
        encoding: "utf8",
        mode: 0o500,
        flag: "wx",
      });

      const digest = await hashDirectory(temporary);
      const target = path.join(objectRoot, digest);
      try {
        await rename(temporary, target);
      } catch (error) {
        const targetInfo = await stat(target).catch(() => null);
        if (!targetInfo?.isDirectory()) {
          throw error;
        }
        if ((await hashDirectory(target)) !== digest) {
          throw new ArtifactError("existing capsule object has invalid content");
        }
      }
      await makeTreeReadonly(target);

      return {
        digest: `sha256:${digest}`,
        entrypoint: [`/data/capsule/${entrypoint}`],
      };
    } finally {
      await makeTreeOwnerWritable(temporary).catch(() => undefined);
      await rm(temporary, { recursive: true, force: true }).catch(() => undefined);
    }
  }

  async persistOutputs(
    outputRoot: string,
    maxFiles: number,
    maxBytes: number,
  ): Promise<StoredArtifact[]> {
    const files = await listRegularFiles(outputRoot);
    if (files.length > maxFiles) {
      throw new ArtifactError(`output contains more than ${maxFiles} files`);
    }

    let totalBytes = 0;
    const artifacts: StoredArtifact[] = [];
    for (const relativePath of files) {
      const source = path.join(outputRoot, ...relativePath.split("/"));
      const info = await stat(source);
      totalBytes += info.size;
      if (totalBytes > maxBytes) {
        throw new ArtifactError(`output exceeds ${maxBytes} bytes`);
      }

      const digest = await hashFile(source);
      const targetDirectory = path.join(this.stateRoot, "artifacts", "sha256");
      const target = path.join(targetDirectory, digest);
      await mkdir(targetDirectory, { recursive: true, mode: 0o700 });
      await copyFileAtomically(source, target);
      artifacts.push({
        path: relativePath,
        ref: `sha256:${digest}`,
        sha256: digest,
        sizeBytes: info.size,
      });
    }

    return artifacts;
  }
}

export async function hashDirectory(root: string): Promise<string> {
  const files = await listRegularFiles(root);
  const digest = createHash("sha256");
  for (const relativePath of files) {
    const absolutePath = path.join(root, ...relativePath.split("/"));
    const info = await stat(absolutePath);
    const fileDigest = await hashFile(absolutePath);
    digest.update("file\0");
    digest.update(relativePath);
    digest.update("\0");
    digest.update(String(info.size));
    digest.update("\0");
    digest.update((info.mode & 0o111) === 0 ? "0" : "1");
    digest.update("\0");
    digest.update(fileDigest);
    digest.update("\n");
  }
  return digest.digest("hex");
}

async function listRegularFiles(root: string): Promise<string[]> {
  const files: string[] = [];

  async function visit(directory: string, prefix: string): Promise<void> {
    const entries = await readdir(directory, { withFileTypes: true });
    entries.sort((left, right) => left.name.localeCompare(right.name));
    for (const entry of entries) {
      const relativePath = prefix ? `${prefix}/${entry.name}` : entry.name;
      const absolutePath = path.join(directory, entry.name);
      const info = await lstat(absolutePath);
      if (info.isSymbolicLink()) {
        throw new ArtifactError(`symbolic links are forbidden: ${relativePath}`);
      }
      if (info.isDirectory()) {
        await visit(absolutePath, relativePath);
        continue;
      }
      if (!info.isFile()) {
        throw new ArtifactError(`non-regular file is forbidden: ${relativePath}`);
      }
      files.push(relativePath);
    }
  }

  const rootInfo = await lstat(root);
  if (!rootInfo.isDirectory()) {
    throw new ArtifactError("artifact root must be a directory");
  }
  await visit(root, "");
  return files;
}

async function copyDirectory(source: string, destination: string): Promise<void> {
  await mkdir(destination, { recursive: false, mode: 0o700 });
  const entries = await readdir(source, { withFileTypes: true });
  entries.sort((left, right) => left.name.localeCompare(right.name));
  for (const entry of entries) {
    const sourcePath = path.join(source, entry.name);
    const targetPath = path.join(destination, entry.name);
    const info = await lstat(sourcePath);
    if (info.isSymbolicLink()) {
      throw new ArtifactError(`symbolic links are forbidden: ${entry.name}`);
    }
    if (info.isDirectory()) {
      await copyDirectory(sourcePath, targetPath);
      continue;
    }
    if (!info.isFile()) {
      throw new ArtifactError(`non-regular file is forbidden: ${entry.name}`);
    }
    await copyFile(sourcePath, targetPath);
    await chmod(targetPath, (info.mode & 0o111) === 0 ? 0o400 : 0o500);
  }
}

async function makeTreeReadonly(root: string): Promise<void> {
  const entries = await readdir(root, { withFileTypes: true });
  for (const entry of entries) {
    const absolutePath = path.join(root, entry.name);
    if (entry.isDirectory()) {
      await makeTreeReadonly(absolutePath);
    }
  }
  await chmod(root, 0o500);
}

async function makeTreeOwnerWritable(root: string): Promise<void> {
  const info = await lstat(root);
  if (info.isSymbolicLink()) {
    return;
  }
  if (!info.isDirectory()) {
    await chmod(root, 0o600);
    return;
  }
  await chmod(root, 0o700);
  const entries = await readdir(root);
  for (const entry of entries) {
    await makeTreeOwnerWritable(path.join(root, entry));
  }
}

async function hashFile(file: string): Promise<string> {
  const handle = await open(file, "r");
  try {
    const digest = createHash("sha256");
    for await (const chunk of handle.createReadStream()) {
      digest.update(chunk);
    }
    return digest.digest("hex");
  } finally {
    await handle.close().catch(() => undefined);
  }
}

async function copyFileAtomically(source: string, target: string): Promise<void> {
  const existing = await stat(target).catch(() => null);
  if (existing) {
    if (!existing.isFile()) {
      throw new ArtifactError("artifact object path is not a regular file");
    }
    const expected = path.basename(target);
    if ((await hashFile(target)) !== expected) {
      throw new ArtifactError("existing artifact object does not match its content digest");
    }
    return;
  }

  const temporary = `${target}.${process.pid}.${randomUUID()}.tmp`;
  try {
    await copyFile(source, temporary);
    await chmod(temporary, 0o400);
    await rename(temporary, target).catch(async (error: unknown) => {
      const code = (error as NodeJS.ErrnoException).code;
      if (code !== "EEXIST") {
        throw error;
      }
    });
  } finally {
    await import("node:fs/promises").then(({ rm }) =>
      rm(temporary, { force: true }).catch(() => undefined),
    );
  }
}

function assertWithin(root: string, candidate: string, label: string): void {
  const relative = path.relative(root, candidate);
  if (
    relative === ".." ||
    relative.startsWith(`..${path.sep}`) ||
    path.isAbsolute(relative)
  ) {
    throw new ArtifactError(`${label} path escapes its configured root`);
  }
}
