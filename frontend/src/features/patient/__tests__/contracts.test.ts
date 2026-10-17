import { parseAppointments } from "@/features/patient/appointments";
import { parseHistory } from "@/features/patient/history";
import { parseProfile } from "@/features/patient/profile";
import { parseCategories, parseServices } from "@/features/patient/catalog";

describe("patient API contracts", () => {
  test("parses appointments including an unscheduled pending request", () => {
    expect(
      parseAppointments({
        data: [
          {
            id: "1",
            paciente_id: "p1",
            fecha_cita: null,
            hora_inicio: null,
            hora_fin: null,
            tipo_cita: "consulta",
            descripcion: null,
            estado: "pendiente",
            created_at: "2026-01-01",
            updated_at: "2026-01-01",
          },
        ],
      })[0].status,
    ).toBe("pendiente");
  });

  test("parses the patient history envelope", () => {
    expect(
      parseHistory({
        data: {
          id: "h1",
          paciente_id: "p1",
          sexo: "F",
          lugar_nacimiento: null,
          antecedentes_enfermedades: null,
          cirugias: null,
          medicacion_actual: null,
          alergias: null,
          antecedentes_familiares: null,
          notas_adicionales: null,
        },
      }).sex,
    ).toBe("F");
  });

  test("parses profile and catalog envelopes", () => {
    expect(
      parseProfile({
        data: {
          foto_perfil_url: null,
          codigo_paciente: "PAC-1",
          nombres: "Ana",
          cedula: "0102",
          telefono: "099",
          direccion: "Quito",
          fecha_nacimiento: "1990-01-01",
        },
      }).code,
    ).toBe("PAC-1");
    expect(
      parseCategories({
        data: [{ id: "c1", nombre: "Consulta", descripcion: null }],
      }),
    ).toHaveLength(1);
    expect(
      parseServices({
        data: [
          {
            id: "s1",
            categoria_servicio_id: "c1",
            nombre: "Evaluación",
            descripcion: null,
            precio: "20.00",
            duracion_minutos: 30,
            imagen: null,
            categoria: { id: "c1", nombre: "Consulta" },
          },
        ],
      })[0].price,
    ).toBe("20.00");
  });
});
