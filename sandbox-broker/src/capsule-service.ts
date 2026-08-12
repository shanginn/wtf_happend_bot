import { canonicalSha256 } from "./canonical-json.js";
import type {
  CapsuleStageRequest,
  CapsuleStageResult,
} from "./contracts.js";
import { CapsuleStageRegistry } from "./capsule-stage-registry.js";
import { TreeStore } from "./tree-store.js";

export class CapsuleService {
  private readonly store: TreeStore;

  constructor(
    private readonly registry: CapsuleStageRegistry,
    capsuleRoot: string,
    stateRoot: string,
  ) {
    this.store = new TreeStore(capsuleRoot, stateRoot);
  }

  stage(
    request: CapsuleStageRequest,
    idempotencyKey: string,
  ): Promise<CapsuleStageResult> {
    const requestSha256 = canonicalSha256(request);
    return this.registry.execute(idempotencyKey, requestSha256, () =>
      this.store.stageJavascriptCapsule(request.source, request.entrypoint),
    );
  }
}
