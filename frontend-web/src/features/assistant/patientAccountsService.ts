import { z } from 'zod'
import { apiClient } from '../../services/apiClient'
import { paginatedSchema } from '../../services/pagination'

const accountSchema = z.object({
  id: z.string(), name: z.string(), email: z.string().nullable(), username: z.string(),
  role: z.enum(['doctora', 'asistente', 'paciente']), paciente_id: z.string().nullable(),
  activo: z.boolean(), created_at: z.string().nullable(),
})
export type Account = z.infer<typeof accountSchema>

function requireApiBaseUrl() {
  if (!apiClient.defaults.baseURL) throw new Error('VITE_API_BASE_URL is not configured')
}

export async function getPatientAccounts(buscar = ''): Promise<Account[]> {
  requireApiBaseUrl()
  const accounts: Account[] = []
  let pageNumber = 1
  while (true) {
    const { data } = await apiClient.get<unknown>('/usuarios', { params: { ...(buscar.trim() ? { buscar: buscar.trim() } : {}), page: pageNumber } })
    const page = paginatedSchema(accountSchema).parse(data)
    if (page.current_page !== pageNumber) throw new Error('La paginación de usuarios no coincide con la página solicitada.')
    accounts.push(...page.data.filter((account) => account.role === 'paciente'))
    if (pageNumber >= page.last_page) return accounts
    pageNumber++
  }
}

async function updateAccount(id: string, action: 'activar' | 'desactivar' | 'desvincular-paciente'): Promise<string> {
  requireApiBaseUrl()
  const { data } = await apiClient.put<unknown>(`/usuarios/${encodeURIComponent(id)}/${action}`)
  return z.object({ message: z.string(), data: accountSchema.pick({ id: true, role: true, paciente_id: true, activo: true }).passthrough() }).parse(data).message
}

export const activatePatientAccount = (id: string) => updateAccount(id, 'activar')
export const deactivatePatientAccount = (id: string) => updateAccount(id, 'desactivar')
export const unlinkPatientAccount = (id: string) => updateAccount(id, 'desvincular-paciente')
