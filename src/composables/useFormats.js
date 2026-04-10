import { formatBytes } from '../utils/files.js'
import moment from '@nextcloud/moment'
import { translate as t } from '@nextcloud/l10n'

const KEEP_ALIVE_INTERVAL = 8
const TARGET_MTYPE_LABELS = [
	t('mediadc', 'Photos'),
	t('mediadc', 'Videos'),
	t('mediadc', 'Photos&Videos'),
]

export function parseUnixTimestamp(time) {
	return moment.unix(Number(time)).format('YYYY-MM-DD HH:mm:ss')
}

export function getStatusBadge(task) {
	if (task === null || task === undefined) {
		return ''
	}
	if (task.errors !== '') {
		return 'error'
	}
	if (Number(task.py_pid) === 0 && Number(task.finished_time) === 0
		&& Number(task.updated_time) > 0 && Number(task.files_scanned) > 0) {
		return 'terminated'
	}
	if (Number(task.py_pid) > 0) {
		if (moment().unix() > Number(task.updated_time) + KEEP_ALIVE_INTERVAL * 3) {
			return 'error'
		}
		return 'running'
	}
	if (Number(task.finished_time) > 0 && Number(task.py_pid) === 0) {
		return 'finished'
	}
	if (JSON.parse(task.collector_settings)?.duplicated) {
		return 'duplicated'
	}
	return 'pending'
}

export function parseTargetMtype(task) {
	if (task) {
		try {
			return TARGET_MTYPE_LABELS[Number(JSON.parse(task.collector_settings).target_mtype)]
		} catch {
			return ''
		}
	}
	return ''
}

export { formatBytes }
