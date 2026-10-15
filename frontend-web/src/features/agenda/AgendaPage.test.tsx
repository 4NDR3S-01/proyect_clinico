import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AgendaPage } from './AgendaPage'
import { createMedicalAppointment, createPersonalEntry, getAgendaMonth, getPendingRequests, updateAgendaEntry } from './agendaService'
import { getPatients } from '../patients/patientService'
import { AuthContext } from '../auth/authContext'
import type { UserRole } from '../auth/authService'

vi.mock('./agendaService', () => ({ getAgendaMonth: vi.fn(), getPendingRequests: vi.fn(), createMedicalAppointment: vi.fn(), createPersonalEntry: vi.fn(), updateAgendaEntry: vi.fn() }))
vi.mock('../patients/patientService', () => ({ getPatients: vi.fn() }))
const personal = { id: 'personal-1', paciente_id: null, fecha: '2026-10-05', hora_inicio: '10:00', hora_fin: '11:00', tipo: 'personal' as const, descripcion: 'Reunión', estado: null, diagnostico: null, tratamiento: null, observacion: null, paciente: null }
const medical = { ...personal, id: 'medical-1', tipo: 'cita_medica' as const, estado: 'programada' as const, descripcion: 'Control recibido', paciente_id: 'patient-1', paciente: { codigo_paciente: '001', nombres: 'Paciente recibido' } }
beforeEach(() => { vi.resetAllMocks(); vi.mocked(getAgendaMonth).mockResolvedValue([personal, medical]) })

function renderAgenda(role: UserRole = 'doctora') {
  render(<AuthContext.Provider value={{ status: 'authenticated', user: { id: 'staff-1', name: 'Personal', username: 'personal', role, paciente_id: null, activo: true }, sessionError: null, login: async () => {}, logout: async () => {} }}><AgendaPage/></AuthContext.Provider>)
}

function selectOctober() {
  fireEvent.change(screen.getByLabelText('Mes'), { target: { value: '2026-10' } })
}

describe('AgendaPage consultation', () => {
  it('shows only real records for the selected day and does not mix in personal entry controls', async () => {
    renderAgenda()
    selectOctober()
    await waitFor(() => expect(getAgendaMonth).toHaveBeenCalledWith(10, 2026))
    expect(screen.queryByRole('button', { name: 'Cita médica' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Actividad personal' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Seleccionar 5 de octubre/ }))
    const day = screen.getByRole('region', { name: '5 de octubre de 2026' })
    expect(day).toHaveTextContent('5 de octubre de 2026')
    expect(await within(day).findByText('2 registros')).toBeInTheDocument()
    expect(day).toHaveTextContent('Reunión')
    expect(day).toHaveTextContent('Control recibido')
    expect(day).toHaveTextContent('Paciente recibido')
    expect(day).toHaveTextContent('10:00–11:00')
    expect(day).toHaveTextContent('Estado: programada')
    expect(within(day).getByRole('button', { name: 'Agendar cita' })).toBeInTheDocument()
    expect(getPendingRequests).not.toHaveBeenCalled()
    expect(createMedicalAppointment).not.toHaveBeenCalled()
    expect(createPersonalEntry).not.toHaveBeenCalled()
    expect(updateAgendaEntry).not.toHaveBeenCalled()
  })

  it('clears visual selection when moving to another month', async () => {
    renderAgenda()
    selectOctober()
    fireEvent.click(screen.getByRole('button', { name: /Seleccionar 5 de octubre/ }))
    expect(screen.getByRole('button', { name: /Seleccionar 5 de octubre/ })).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(screen.getByRole('button', { name: 'Mes siguiente' }))
    expect(screen.getByRole('button', { name: /Seleccionar 1 de noviembre/ })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByRole('heading', { name: 'Selecciona un día' })).toBeInTheDocument()
    await waitFor(() => expect(getAgendaMonth).toHaveBeenCalledWith(11, 2026))
  })

  it('keeps an honest empty day and retryable load error', async () => {
    vi.mocked(getAgendaMonth).mockResolvedValueOnce([]).mockRejectedValueOnce(new Error('No disponible')).mockResolvedValueOnce([])
    renderAgenda()
    selectOctober()
    fireEvent.click(screen.getByRole('button', { name: /Seleccionar 5 de octubre/ }))
    expect(await screen.findByText('Sin registros para este día.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Mes siguiente' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar la agenda.')
    fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))
    await waitFor(() => expect(getAgendaMonth).toHaveBeenCalledTimes(3))
  })

  it('creates a medical appointment on the selected day using a real patient code, then reloads Agenda', async () => {
    vi.mocked(getPatients).mockResolvedValue({ data: [{ id: 'patient-1', codigo_paciente: '001', nombres: 'Paciente recibido', cedula: '0912345678', telefono: null, direccion: null, fecha_nacimiento: null }], current_page: 1, last_page: 1, per_page: 20, total: 1, next_page_url: null, prev_page_url: null })
    vi.mocked(createMedicalAppointment).mockResolvedValue('Cita registrada')
    renderAgenda()
    selectOctober()
    fireEvent.click(screen.getByRole('button', { name: /Seleccionar 5 de octubre/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Agendar cita' }))
    const form = screen.getByRole('form', { name: 'Agendar cita médica' })
    expect(form).toHaveTextContent('5 de octubre de 2026')
    fireEvent.change(within(form).getByLabelText('Código de paciente'), { target: { value: '001' } })
    fireEvent.change(within(form).getByLabelText('Hora de inicio'), { target: { value: '09:00' } })
    fireEvent.change(within(form).getByLabelText('Hora de fin'), { target: { value: '10:00' } })
    fireEvent.submit(form)
    fireEvent.submit(form)
    await waitFor(() => expect(createMedicalAppointment).toHaveBeenCalledExactlyOnceWith({ codigo_paciente: '001', fecha: '2026-10-05', hora_inicio: '09:00', hora_fin: '10:00', descripcion: null }))
    expect(getPatients).toHaveBeenCalledWith({ buscar: '001' })
    expect(await screen.findByText('Cita médica registrada correctamente.')).toBeInTheDocument()
    expect(vi.mocked(getAgendaMonth).mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('does not offer appointment creation to an assistant', async () => {
    renderAgenda('asistente')
    selectOctober()
    fireEvent.click(screen.getByRole('button', { name: /Seleccionar 5 de octubre/ }))
    expect(await within(screen.getByRole('region', { name: '5 de octubre de 2026' })).findByText('2 registros')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Agendar cita' })).not.toBeInTheDocument()
  })

  it.each([[409, 'Horario ocupado.'], [422, 'Horario no válido.']] as const)('rejects an unknown patient code and shows backend %s without inventing a booking', async (status, message) => {
    vi.mocked(getPatients).mockResolvedValueOnce({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0, next_page_url: null, prev_page_url: null }).mockResolvedValueOnce({ data: [{ id: 'patient-1', codigo_paciente: '001', nombres: 'Paciente recibido', cedula: '0912345678', telefono: null, direccion: null, fecha_nacimiento: null }], current_page: 1, last_page: 1, per_page: 20, total: 1, next_page_url: null, prev_page_url: null })
    vi.mocked(createMedicalAppointment).mockRejectedValue({ isAxiosError: true, response: { status, data: { message } } })
    renderAgenda()
    selectOctober()
    fireEvent.click(screen.getByRole('button', { name: /Seleccionar 5 de octubre/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Agendar cita' }))
    const form = screen.getByRole('form', { name: 'Agendar cita médica' })
    fireEvent.change(within(form).getByLabelText('Código de paciente'), { target: { value: '001' } })
    fireEvent.change(within(form).getByLabelText('Hora de inicio'), { target: { value: '09:00' } })
    fireEvent.change(within(form).getByLabelText('Hora de fin'), { target: { value: '10:00' } })
    fireEvent.submit(form)
    expect(await within(form).findByRole('alert')).toHaveTextContent('No se encontró un paciente con ese código')
    expect(createMedicalAppointment).not.toHaveBeenCalled()
    fireEvent.submit(form)
    expect(await within(form).findByRole('alert')).toHaveTextContent(message)
    expect(createMedicalAppointment).toHaveBeenCalledOnce()
  })
})
