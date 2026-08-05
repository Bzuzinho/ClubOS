<?php

namespace App\Services\Website;

class WebsitePageTemplateService
{
    /** @return array<int, array<string, mixed>> */
    public function starterBlocks(string $slug): array
    {
        return match ($slug) {
            'home' => $this->home(),
            'clube' => $this->clube(),
            'competicao' => $this->competicao(),
            'treinos' => $this->treinos(),
            'noticias' => $this->noticias(),
            'calendario' => $this->calendario(),
            'parceiros' => $this->parceiros(),
            'contactos' => $this->contactos(),
            'junta-te' => $this->contactForm(),
            'inscricao' => $this->registrationForm(),
            'privacidade' => $this->privacy(),
            default => $this->custom(),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function home(): array
    {
        return [
            $this->block('hero', 'hero', [
                'eyebrow' => 'Benedita · Natação de competição',
                'title' => 'Trabalho, ritmo e ambição.',
                'text' => 'Dos 6 anos aos Masters, treinamos para evoluir — com método, compromisso e uma equipa que cresce dentro e fora de água.',
                'image' => '/site-assets/bscn-hero-bright.webp',
                'image_position' => 'center 52%',
                'primary_label' => 'Inscreve-te',
                'primary_url' => '/inscricao',
                'secondary_label' => 'Conhecer o clube',
                'secondary_url' => '/clube',
            ]),
            $this->block('news_feed', 'news', ['eyebrow' => 'Atualidade', 'title' => 'Notícias do clube', 'intro' => 'Informação publicada diretamente no ClubOS.', 'limit' => 3]),
            $this->block('events_feed', 'events', ['eyebrow' => 'Agenda', 'title' => 'Próximas datas', 'intro' => 'Eventos públicos confirmados pelo clube.', 'limit' => 3]),
            $this->block('rich_text', 'identity', [
                'eyebrow' => 'Um clube com direção',
                'title' => 'Não somos uma escola de aprendizagem. Somos um projeto de competição.',
                'text' => 'O BSCN acompanha cada atleta de acordo com a idade, experiência e objetivos. Técnica, compromisso e evolução fazem parte do mesmo percurso — desde os primeiros anos de competição até aos Masters.',
            ]),
            $this->block('cards', 'programmes', [
                'eyebrow' => 'Encontra o teu percurso',
                'title' => 'Natação com um lugar para cada ambição.',
                'columns' => 4,
                'items' => [
                    ['label' => 'A partir dos 6 anos', 'title' => 'Formação competitiva', 'text' => 'Avaliação técnica e integração progressiva no grupo adequado.', 'url' => '/competicao#formacao'],
                    ['label' => 'Evolução e prova', 'title' => 'Competição', 'text' => 'Treino estruturado para evoluir e construir resultados consistentes.', 'url' => '/competicao#competicao'],
                    ['label' => 'Sem limite de idade', 'title' => 'Masters', 'text' => 'Competição com método, objetivos e espírito de equipa.', 'url' => '/competicao#masters'],
                    ['label' => 'Triatlo e outras modalidades', 'title' => 'Treino complementar', 'text' => 'Trabalho específico de natação ajustado a outros objetivos desportivos.', 'url' => '/competicao#complementar'],
                ],
            ]),
            $this->block('image_text', 'training', [
                'eyebrow' => 'Treinar na Benedita',
                'title' => 'Informação prática, sem caça ao tesouro.',
                'text' => 'Treinamos nas Piscinas Municipais da Benedita. Os horários variam por escalão e são publicados depois da confirmação das pistas.',
                'image' => '/site-assets/bscn-training-bright.webp',
                'image_position' => 'center',
                'image_side' => 'left',
                'items' => ['Grupos definidos pela equipa técnica', 'Horários adequados a cada escalão', 'Avaliação antes da integração'],
                'button_label' => 'Treinos e horários',
                'button_url' => '/treinos',
            ]),
            $this->block('cta', 'final-cta', [
                'eyebrow' => 'Próxima etapa',
                'title' => 'Queres saber se o BSCN é o clube certo para ti?',
                'text' => 'Se já decidiste avançar, inicia o registo. Se ainda tens dúvidas, fala primeiro connosco.',
                'button_label' => 'Inscreve-te',
                'button_url' => '/inscricao',
                'secondary_label' => 'Pedir contacto',
                'secondary_url' => '/junta-te',
            ]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function clube(): array
    {
        return [
            $this->pageHero('O clube', 'Mais do que uma equipa. Um percurso partilhado.', 'O BSCN nasceu na Benedita e cresceu com uma ideia simples: dar à ambição uma estrutura onde possa evoluir.', '/site-assets/bscn-club-bright.webp'),
            $this->block('rich_text', 'identity', ['eyebrow' => 'Desde 2008', 'title' => 'O clube é aquilo que acontece entre o esforço individual e o objetivo coletivo.', 'text' => 'Treinamos atletas. Mas também construímos hábitos, relações e uma cultura que continua depois de sair da água. Na Benedita, a natação de competição tem casa — e essa casa cresce com cada geração.']),
            $this->block('stats', 'facts', ['items' => [['value' => '2008', 'label' => 'Ano de fundação'], ['value' => '6+', 'label' => 'Idade de entrada'], ['value' => '∞', 'label' => 'Sem limite nos Masters'], ['value' => '1', 'label' => 'Projeto competitivo']]]),
            $this->block('cards', 'values', ['eyebrow' => 'A nossa forma de estar', 'title' => 'Exigentes no trabalho. Claros no propósito.', 'columns' => 4, 'items' => [
                ['label' => '01', 'title' => 'Ambição', 'text' => 'Querer mais é saudável quando se aprende a trabalhar melhor.'],
                ['label' => '02', 'title' => 'Consistência', 'text' => 'O talento chama a atenção. A regularidade é o que muda resultados.'],
                ['label' => '03', 'title' => 'Comunidade', 'text' => 'Ninguém treina sozinho, mesmo quando a prova tem apenas uma pista.'],
                ['label' => '04', 'title' => 'Responsabilidade', 'text' => 'Com atletas, famílias, equipa técnica e a comunidade que nos apoia.'],
            ]]),
            $this->block('cta', 'next', ['eyebrow' => 'O próximo capítulo', 'title' => 'O clube continua a ser escrito por quem entra.', 'text' => '', 'button_label' => 'Conhecer as condições', 'button_url' => '/junta-te']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function competicao(): array
    {
        return [
            $this->pageHero('Competição', 'Precisão na água. Intenção em cada treino.', 'Não ensinamos apenas a nadar mais depressa. Ensinamos a compreender o trabalho que torna isso possível.', '/site-assets/bscn-training-bright.webp'),
            $this->block('rich_text', 'project', ['eyebrow' => 'Competir é consequência', 'title' => 'A evolução nasce de um plano, não de um slogan.', 'text' => 'O BSCN é exclusivamente um clube de competição. Cada atleta é enquadrado de acordo com idade, experiência, objetivos e disponibilidade, com avaliação da equipa técnica.']),
            $this->block('cards', 'pathways', ['eyebrow' => 'Percursos', 'title' => 'Um método ajustado a cada etapa.', 'columns' => 2, 'items' => [
                ['label' => 'A partir dos 6 anos', 'title' => 'Formação competitiva', 'text' => 'Uma base técnica exigente e progressiva para jovens atletas.', 'url' => '#formacao'],
                ['label' => 'Evolução e prova', 'title' => 'Competição', 'text' => 'Planeamento orientado para rendimento, evolução individual e objetivos de equipa.', 'url' => '#competicao'],
                ['label' => 'Sem limite de idade', 'title' => 'Masters', 'text' => 'Competir, evoluir e pertencer a uma equipa sem que a idade seja um ponto final.', 'url' => '#masters'],
                ['label' => 'Outras modalidades', 'title' => 'Treino complementar', 'text' => 'Trabalho de natação para triatletas e praticantes de outras modalidades.', 'url' => '#complementar'],
            ]]),
            $this->block('image_text', 'method', ['eyebrow' => 'Método', 'title' => 'Treinar melhor é tornar visível aquilo que ainda pode evoluir.', 'text' => 'Técnica, condição física, consistência e leitura da prova fazem parte do mesmo processo.', 'image' => '/site-assets/bscn-training-bright.webp', 'image_side' => 'left', 'button_label' => 'Pedir avaliação técnica', 'button_url' => '/junta-te']),
            $this->block('cta', 'schedule', ['eyebrow' => 'Horários', 'title' => 'Os horários definitivos são publicados após confirmação das pistas.', 'text' => 'Indica a tua disponibilidade no formulário e entraremos em contacto.', 'button_label' => 'Pedir contacto', 'button_url' => '/junta-te']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function treinos(): array
    {
        return [
            $this->pageHero('Treinos', 'Cada grupo tem o seu ritmo. O método é comum.', 'Grupos organizados pela equipa técnica, de acordo com idade, experiência, objetivos e disponibilidade de pistas.', '/site-assets/bscn-training-bright.webp'),
            $this->block('rich_text', 'overview', ['eyebrow' => 'Treinar com propósito', 'title' => 'O horário é importante. O enquadramento certo é decisivo.', 'text' => 'Antes da integração, a equipa técnica analisa o percurso do atleta e identifica o grupo adequado. O BSCN não funciona como escola de aprendizagem.']),
            $this->block('cards', 'groups', ['eyebrow' => 'Grupos', 'title' => 'Percursos desportivos', 'columns' => 2, 'items' => [
                ['label' => 'A partir dos 6 anos', 'title' => 'Formação competitiva', 'text' => 'Integração progressiva para jovens com bases de natação e vontade de competir.'],
                ['label' => 'Juvenis a seniores', 'title' => 'Competição', 'text' => 'Treino orientado para evolução técnica, condição física e participação em provas.'],
                ['label' => 'Sem limite de idade', 'title' => 'Masters', 'text' => 'Preparação para atletas adultos que querem competir e melhorar marcas.'],
                ['label' => 'Outras modalidades', 'title' => 'Treino complementar', 'text' => 'Natação específica para triatletas e outros praticantes.'],
            ]]),
            $this->block('cards', 'practical', ['eyebrow' => 'Informação prática', 'title' => 'Antes do primeiro treino', 'columns' => 3, 'items' => [
                ['title' => 'Local', 'text' => 'Piscinas Municipais da Benedita, concelho de Alcobaça.'],
                ['title' => 'O que trazer', 'text' => 'A equipa técnica indica o material adequado ao grupo e à avaliação.'],
                ['title' => 'Como começar', 'text' => 'Preenche o formulário. Não é necessário deslocares-te sem marcação.'],
            ]]),
            $this->block('cta', 'evaluation', ['eyebrow' => 'Primeiro contacto', 'title' => 'Queres perceber qual é o grupo adequado para ti?', 'text' => '', 'button_label' => 'Pedir avaliação', 'button_url' => '/junta-te']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function noticias(): array
    {
        return [
            $this->pageHero('Notícias', 'O clube está sempre em movimento.', 'Informação sobre a época, competições, conquistas e a vida diária do BSCN.', '/site-assets/bscn-news-bright.webp'),
            $this->block('news_feed', 'news', ['eyebrow' => 'Do BSCN', 'title' => 'Notícias e histórias do clube', 'intro' => 'As publicações criadas no ClubOS aparecem automaticamente nesta página.', 'limit' => 12]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function calendario(): array
    {
        return [
            $this->pageHero('Calendário', 'A época, organizada.', 'Eventos públicos do BSCN, sem confundir calendário com convocatória.', '/site-assets/bscn-hero-bright.webp'),
            $this->block('events_feed', 'events', ['eyebrow' => 'Agenda do clube', 'title' => 'Próximos eventos públicos', 'intro' => 'Apenas aparecem eventos confirmados como públicos. Os detalhes individuais continuam reservados aos membros.', 'limit' => 30]),
            $this->block('cta', 'clubos', ['eyebrow' => 'Informação do clube', 'title' => 'Convocatórias e detalhes ficam disponíveis no ClubOS.', 'text' => '', 'button_label' => 'Entrar no ClubOS', 'button_url' => '/login']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function parceiros(): array
    {
        return [
            $this->pageHero('Parceiros', 'Apoiar o desporto local é investir em continuidade.', 'Empresas e instituições que ajudam a transformar trabalho, talento e comunidade em oportunidades reais.', '/site-assets/bscn-club-bright.webp'),
            $this->block('rich_text', 'partnership', ['eyebrow' => 'Valor partilhado', 'title' => 'Uma parceria deve criar impacto, não apenas ocupar espaço num cartaz.', 'text' => 'Construímos relações ajustadas aos objetivos de cada parceiro e às necessidades do clube.']),
            $this->block('cards', 'options', ['eyebrow' => 'Formas de apoiar', 'title' => 'Parcerias ajustadas', 'columns' => 3, 'items' => [
                ['label' => '01', 'title' => 'Parceiro de época', 'text' => 'Associação continuada ao projeto competitivo e à comunicação institucional.'],
                ['label' => '02', 'title' => 'Parceiro de evento', 'text' => 'Apoio orientado para uma competição, estágio ou iniciativa específica.'],
                ['label' => '03', 'title' => 'Bens ou serviços', 'text' => 'Contributos materiais ou especializados que melhorem as condições.'],
            ]]),
            $this->block('cta', 'partner', ['eyebrow' => 'Vamos conversar', 'title' => 'Há muitas formas de fazer parte deste percurso.', 'text' => 'Preparamos uma proposta ajustada ao parceiro e às necessidades reais do clube.', 'button_label' => 'Quero ser parceiro', 'button_url' => 'mailto:geral@bscn.pt?subject=Parceria%20com%20o%20BSCN']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function contactos(): array
    {
        return [
            $this->pageHero('Contactos', 'Fala com a pessoa certa, sem voltas à piscina.', 'Inscrições, informação desportiva, parcerias ou apoio: escolhe o assunto e entra em contacto.', '/site-assets/bscn-hero-bright.webp'),
            $this->block('cards', 'contacts', ['eyebrow' => 'Estamos disponíveis', 'title' => 'Como podemos ajudar?', 'columns' => 2, 'items' => [
                ['label' => 'Inscrição', 'title' => 'Quero treinar no BSCN', 'text' => 'Iniciar registo de atleta', 'url' => '/inscricao'],
                ['label' => 'Pedido de contacto', 'title' => 'Ainda tenho dúvidas', 'text' => 'Pedir contacto ao clube', 'url' => '/junta-te'],
                ['label' => 'Informação geral', 'title' => 'Clube e atividade desportiva', 'text' => 'geral@bscn.pt', 'url' => 'mailto:geral@bscn.pt'],
                ['label' => 'Área reservada', 'title' => 'Acesso ao ClubOS', 'text' => 'Entrar na aplicação', 'url' => '/login'],
            ]]),
            $this->block('rich_text', 'location', ['eyebrow' => 'Onde treinamos', 'title' => 'Piscinas Municipais da Benedita', 'text' => 'Benedita, concelho de Alcobaça, Portugal. Antes de te deslocares, marca primeiro o contacto.']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function contactForm(): array
    {
        return [
            $this->pageHero('Pedir contacto', 'Vamos perceber como te podemos ajudar.', 'Usa este formulário para esclarecer condições, horários ou o enquadramento desportivo antes de iniciares o registo.', '/site-assets/bscn-club-bright.webp'),
            $this->block('contact_form', 'contact-form', ['eyebrow' => 'Pedido de contacto', 'title' => 'Conta-nos o que precisas de saber.', 'text' => 'Este pedido não é uma inscrição. A equipa entra em contacto para esclarecer condições e disponibilidade.', 'steps' => ['Envio do pedido', 'Contacto do clube', 'Esclarecimento ou avaliação']]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function registrationForm(): array
    {
        return [
            $this->pageHero('Inscreve-te', 'Começa o teu registo no BSCN.', 'Preenche os dados do percurso desportivo e a equipa do clube valida a integração.', '/site-assets/bscn-training-bright.webp'),
            $this->block('registration_form', 'registration-form', ['eyebrow' => 'Registo de atleta', 'title' => 'Um processo completo, sem confundir inscrição com pedido de informação.', 'text' => 'O registo não substitui a validação da equipa técnica nem confirma automaticamente uma vaga.', 'steps' => ['Dados do atleta', 'Percurso desportivo', 'Validação pelo clube', 'Integração no ClubOS']]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function privacy(): array
    {
        return [
            $this->block('rich_text', 'privacy', [
                'eyebrow' => 'Privacidade',
                'title' => 'Informação sobre o tratamento de dados',
                'text' => "Os dados enviados através dos formulários são utilizados pelo Benedita Sport Club Natação exclusivamente para responder a pedidos de contacto, avaliar o enquadramento desportivo e gerir o processo inicial de registo no clube.\n\nNo pedido de contacto recolhemos nome, data de nascimento, contactos, experiência e assunto. No registo de atleta recolhemos ainda localidade, percurso desportivo, disponibilidade e, quando aplicável, dados do encarregado de educação.\n\nOs pedidos e registos são conservados apenas durante o período necessário ao contacto e processo de integração. Podes solicitar acesso, correção ou eliminação através de geral@bscn.pt.",
            ]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function custom(): array
    {
        return [
            $this->pageHero('Nova página', 'Título principal da página', 'Explica aqui, de forma breve, o objetivo desta página.', '/site-assets/bscn-club-bright.webp'),
            $this->block('rich_text', 'content', ['eyebrow' => 'Conteúdo', 'title' => 'Primeira secção', 'text' => 'Escreve aqui o conteúdo da nova página.']),
        ];
    }

    private function pageHero(string $eyebrow, string $title, string $text, string $image): array
    {
        return $this->block('hero', 'hero', [
            'eyebrow' => $eyebrow,
            'title' => $title,
            'text' => $text,
            'image' => $image,
            'image_position' => 'center',
            'primary_label' => '',
            'primary_url' => '',
            'secondary_label' => '',
            'secondary_url' => '',
        ]);
    }

    /** @param array<string, mixed> $content */
    private function block(string $type, string $key, array $content): array
    {
        return [
            'block_key' => $key,
            'type' => $type,
            'is_visible' => true,
            'content' => $content,
        ];
    }
}
