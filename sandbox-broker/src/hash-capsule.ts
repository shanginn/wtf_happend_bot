import path from "node:path";

import { hashDirectory } from "./tree-store.js";

const directory = process.argv[2];
if (!directory) {
  process.stderr.write("usage: pnpm hash-capsule <directory>\n");
  process.exitCode = 2;
} else {
  const digest = await hashDirectory(path.resolve(directory));
  process.stdout.write(`sha256:${digest}\n`);
}
