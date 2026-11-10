// Copyright 2026 Google LLC
// SPDX-License-Identifier: Apache-2.0
// Modified: TypeScript port of Harness² commit 6868817518876de8fd170c6a8354daca273b6191.
// The bounded Pi adapter uses in-memory evidence instead of upstream CLI workspaces.
import type { HarnessTree } from '../skills.js';

export type Evidence = Record<string, string>;
export interface Harness2Request {
  taskId: string;
  domain: string;
  prompt: string;
  base: HarnessTree;
  k: 1 | 2;
  mode: 'parallel' | 'sequential';
  libraryRoot?: string;
  signal: AbortSignal;
  /** The previous Pi bridge serialized phases; two workers match the upstream default. */
  phaseWidth?: 1 | 2;
}
export type Propose = (label: string, mission: string, evidence: Evidence) => Promise<{ text: string; files?: HarnessTree; costMicros?: number }>;
export interface Harness2Callbacks {
  propose: Propose;
  probe(label: string, tree: HarnessTree, prompt: string): Promise<{ answer: string; trajectory: string; costMicros?: number }>;
  validate(tree: HarnessTree): Promise<void>;
}
export interface Candidate {
  tree: HarnessTree;
  answer: string;
  trajectory: string;
  ok: boolean;
  delivered: boolean;
  error?: string;
}
export interface Selection {
  label: string;
  why: string;
  idMap: Record<string, string>;
  trivial?: boolean;
}
export type Experience = Record<string, unknown>;
export interface Harness2Result {
  tree: HarnessTree;
  selectedLabel: string;
  experience: Experience;
  summary: Record<string, unknown>;
}
export interface Stage {
  label: string;
  dim: 'INV' | 'DOM' | 'AGG' | 'COMPOSE';
  tree: HarnessTree;
  kept_base: boolean;
  fell_back: boolean;
  execution_failed: boolean;
  error: string;
  attempts: number;
  notes: string;
  usd: number;
  cached: boolean;
}
