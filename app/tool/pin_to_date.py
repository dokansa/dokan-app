"""Pin every pub.dev package to the version that was current at a given time.

Over-the-air patches must be built with the same plugin versions as the store release,
otherwise Shorebird refuses the patch ("native changes"). The repo has no lockfile from the
release day, so this rebuilds it: for each package now resolved, take the newest version
published before the cutoff that is not newer than today's pick, and write
pubspec_overrides.yaml.

usage: flutter pub deps --json > deps.json && python3 tool/pin_to_date.py deps.json 2026-10-31T21:30:00Z
"""
import json
import sys
import urllib.request


def key(v):
    main, _, pre = v.partition('-')
    main = main.split('+')[0]
    nums = []
    for p in main.split('.'):
        try:
            nums.append(int(p))
        except ValueError:
            nums.append(0)
    while len(nums) < 3:
        nums.append(0)
    return (nums, 0 if pre else 1, pre)


def main():
    deps = json.load(open(sys.argv[1]))
    cutoff = sys.argv[2]
    pins = {}
    for p in deps.get('packages', []):
        if p.get('source') != 'hosted':
            continue
        name, cur = p['name'], p['version']
        try:
            with urllib.request.urlopen(f'https://pub.dev/api/packages/{name}', timeout=20) as r:
                info = json.load(r)
        except Exception as e:  # noqa: BLE001
            print(f'skip {name}: {e}')
            continue
        best = None
        for v in info.get('versions', []):
            ver, pub = v['version'], v.get('published', '')
            if pub > cutoff or key(ver) > key(cur):
                continue
            if '-' in ver and '-' not in cur:
                continue
            if best is None or key(ver) > key(best):
                best = ver
        if best and best != cur:
            print(f'{name}: {cur} -> {best}')
        if best:
            pins[name] = best
    with open('pubspec_overrides.yaml', 'w') as f:
        f.write('dependency_overrides:\n')
        for n in sorted(pins):
            f.write(f'  {n}: {pins[n]}\n')
    print(f'pinned {len(pins)} packages to {cutoff}')


if __name__ == '__main__':
    main()
