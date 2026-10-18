import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from '../../services/apiClient'
import { getPatientAccounts } from './patientAccountsService'

const originalBase = apiClient.defaults.baseURL
afterEach(() => { apiClient.defaults.baseURL = originalBase; vi.restoreAllMocks() })

describe('getPatientAccounts', () => {
  it('returns only complete patient results after all Laravel pages arrive', async () => {
    apiClient.defaults.baseURL = 'https://api.test/api'
    const patient = { id: 'user-1', name: 'Cuenta paciente', email: null, username: '0912345678', role: 'paciente', paciente_id: 'patient-1', activo: true, created_at: null }
    const assistant = { ...patient, id: 'user-2', role: 'asistente', paciente_id: null }
    const page = (data: unknown[], current_page: number) => ({ data, current_page, last_page: 2, per_page: 20, total: 3, next_page_url: current_page === 1 ? '/usuarios?page=2' : null, prev_page_url: current_page === 2 ? '/usuarios?page=1' : null })
    const get = vi.spyOn(apiClient, 'get').mockResolvedValueOnce({ data: page([patient, assistant], 1) }).mockResolvedValueOnce({ data: page([{ ...patient, id: 'user-3' }], 2) })
    expect(await getPatientAccounts()).toEqual([patient, { ...patient, id: 'user-3' }])
    expect(get).toHaveBeenCalledTimes(2)
  })

  it('stops a superseded paginated scan before requesting another page', async () => {
    apiClient.defaults.baseURL = 'https://api.test/api'
    let finishFirst!: (value: { data: unknown }) => void
    const get = vi.spyOn(apiClient, 'get').mockImplementation(() => new Promise((resolve) => { finishFirst = resolve }))
    const controller = new AbortController()
    const scan = getPatientAccounts('María', controller.signal)
    expect(get).toHaveBeenCalledExactlyOnceWith('/usuarios', { params: { buscar: 'María', page: 1 }, signal: controller.signal })
    controller.abort()
    finishFirst({ data: { data: [], current_page: 1, last_page: 3, per_page: 20, total: 60, next_page_url: '/usuarios?page=2', prev_page_url: null } })
    await expect(scan).rejects.toMatchObject({ name: 'AbortError' })
    expect(get).toHaveBeenCalledTimes(1)
  })
})
