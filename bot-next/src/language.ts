/** A quoted Chinese phrase or an explicitly requested translation is valid; an unsolicited Chinese answer is not. */
export function needsRussianRepair(request: string, answer: string): boolean {
  const chineseTarget = /на\s+китайск|по[-\s]?китайски|(?:in|into)\s+chinese|中文/iu.test(request);
  const explicit = /(?:^|[\s,])(?:переведи|перевести|напиши|пиши|ответь|отвечай|скажи|говори|translate|write|reply|respond)(?:[\s,:]|$)/iu.test(request);
  const russianTarget = /на\s+русск|по[-\s]?русски|(?:in|into)\s+russian/iu.test(request);
  const negative = /(?:не|not|don't)[^.!?\n]{0,35}(?:китайск|chinese|中文)/iu.test(request);
  if (chineseTarget && explicit && !russianTarget && !negative) return false;
  if (/\p{Script=Han}/u.test(request) && !/\p{Script=Cyrillic}/u.test(request)) return false;
  const prose = answer.replace(/```[\s\S]*?```|`[^`]*`|https?:\/\/\S+/g, '');
  const han = prose.match(/\p{Script=Han}/gu)?.length ?? 0, letters = prose.match(/\p{L}/gu)?.length ?? 0;
  return han >= 4 && han / Math.max(letters, 1) > 0.4;
}
