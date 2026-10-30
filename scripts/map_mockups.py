"""Map every original flat reference image to its implementation task(s).

Aliases are explicit, based on 02b_mockup_journal and 10_backlog. They are not
new product screens and do not change imported task ownership or dependencies.
"""
import re


def screens_for(image):
    screens = []
    for hint in image['screenHints']:
        if re.fullmatch(r'(S-0\.[46]|D-9\.2)[a-d]', hint):
            screens.append(hint[:-1])
        elif hint == 'V-5':
            section = re.search(r'V-5-report-(\d\d)-', image['relative'])
            report_sections = {
                '01': [1], '02': [2], '03': [3, 4], '04': [5, 6],
                '05': [7], '06': [8, 9], '07': list(range(1, 10)),
            }
            if not section or section[1] not in report_sections:
                raise ValueError('Unknown report image: ' + image['relative'])
            screens.extend('V-5.' + str(n) for n in report_sections[section[1]])
        else:
            screens.append(hint)
    return sorted(set(screens))


def owners_for(image, tasks):
    screens = screens_for(image)
    owners = {task['taskKey'] for task in tasks if set(screens) & set(task['screenIds'])}
    if 'V-5.P' in screens:
        owners.add('SP-E8-05C')
    return sorted(owners)


def build_coverage(images, tasks):
    tasks = list(tasks)
    rows = []
    for image in images:
        if not image['relative'].split('/')[0].isdigit():
            continue
        screens = screens_for(image)
        owners = owners_for(image, tasks)
        excluded = 'S-0.7' in screens
        if not owners and not excluded:
            raise ValueError('Unassigned original image: ' + image['relative'])
        rows.append({
            'relative': image['relative'], 'path': image['path'],
            'sha256': image['sha256'], 'screenHints': image['screenHints'],
            'screens': screens, 'taskKeys': owners,
            'status': 'excluded' if excluded else 'planned',
            'note': 'Выбор организации исключён пользователем.' if excluded else
                    'Плоский визуальный эталон; все показанные состояния воспроизводятся компонентами.',
        })
    return {
        'originals': len(rows), 'planned': sum(row['status'] == 'planned' for row in rows),
        'excluded': sum(row['status'] == 'excluded' for row in rows),
        'derivativesIncluded': False, 'images': rows,
    }
